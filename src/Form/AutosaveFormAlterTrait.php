<?php

namespace Drupal\silverback_autosave\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Provides a trait for common autosave form alterations.
 */
trait AutosaveFormAlterTrait {

  use StringTranslationTrait;
  use AutosaveButtonClickedTrait;

  /**
   * Performs the needed alterations to the form.
   *
   * @param array $form
   *   The form to be altered to provide the autosave form support.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function formAlter(array &$form, FormStateInterface $form_state) {
    if (!$this->isAutosaveEnabled($form_state)) {
      return;
    }

    $form['#attributes']['class'][] = 'autosave-form';
    $form['#attached']['library'][] = 'silverback_autosave/drupal.silverback_autosave';
    $form['#attached']['drupalSettings']['autosaveForm']['interval'] = $this->configFactory->get('silverback_autosave.settings')->get('interval');
    $form['#attached']['drupalSettings']['autosaveForm']['onlyOnFormChange'] = $this->configFactory->get('silverback_autosave.settings')->get('only_on_form_change');
    $form['#attached']['drupalSettings']['autosaveForm']['notification'] = $this->configFactory->get('silverback_autosave.settings')->get('notification');
    $input = $form_state->getUserInput();

    $silverback_autosave_session_id = $this->getAutosaveFormSessionID($form_state);
    if (!$silverback_autosave_session_id) {
      $silverback_autosave_session_id = !empty($input['silverback_autosave_session_id']) ?
        $input['silverback_autosave_session_id'] :
        $form['#build_id'];
      $this->setAutosaveFormSessionID($form_state, $silverback_autosave_session_id);
    }

    $form['silverback_autosave_session_id'] = [
      '#type' => 'hidden',
      '#value' => $silverback_autosave_session_id,
      '#name' => 'silverback_autosave_session_id',
      // Form processing and validation requires this value, so ensure the
      // submitted form value appears literally, regardless of custom #tree
      // and #parents being set elsewhere.
      '#parents' => ['silverback_autosave_session_id'],
    ];

    $form[AutosaveFormInterface::AUTOSAVE_ELEMENT_NAME] = [
      '#type' => 'submit',
      '#name' => AutosaveFormInterface::AUTOSAVE_ELEMENT_NAME,
      '#value' => $this->t('Autosave save'),
      '#attributes' => ['class' => ['autosave-form-save', 'visually-hidden']],
      '#submit' => [[$this, 'autosaveFormSubmit']],
      '#ajax' => [
        'callback' => [$this, 'autosaveFormAjax'],
        // Do not refocus to prevent losing focus of the element the user might
        // be currently editing when the autosave submission is triggered.
        'disable-refocus' => TRUE,
        'progress' => FALSE,
      ],
      '#silverback_autosave' => TRUE,
      // Retrieve the "silverback_autosave_session_id" also from the form state as on
      // autosave restore the one from the restored state will be present in
      // the form state storage and we want to continue using that session for
      // the further autosave states after the restoration.
      '#silverback_autosave_session_id' => $silverback_autosave_session_id,
    ];

    $form['purge'] = [
      '#type' => 'submit',
      '#id' => 'purge-button',
      '#name' => 'autosave_form_purge',
      '#value' => $this->t('Autosave purge'),
      '#limit_validation_errors' => [],
      '#attributes' => ['class' => ['autosave-form-purge', 'visually-hidden']],
      '#submit' => [[$this, 'autosaveFormPurgeSubmit']],
      '#ajax' => [
        'callback' => [$this, 'autosaveFormPurgeAjax'],
        'event' => 'click',
      ],
    ];

    $form['silverback_autosave_last_autosave_timestamp'] = [
      '#type' => 'hidden',
      '#name' => 'silverback_autosave_last_autosave_timestamp',
      '#value' => $form_state->get('silverback_autosave_last_autosave_timestamp') ?: '',
    ];
  }

  /**
   * Form submission handler for rejecting autosaved states.
   */
  public function autosaveFormPurgeSubmit($form, FormStateInterface $form_state) {
    \Drupal::logger('debug')->debug(__METHOD__);
    $this->purgeAllAutosavedStates($form_state, $this->currentUser->id());
  }

  /**
   * Ajax callback for rejecting autosaved states.
   */
  public function autosaveFormPurgeAjax($form, FormStateInterface $form_state) {
    \Drupal::logger('debug')->debug(__METHOD__);
    return new AjaxResponse();
  }

  /**
   * Form submission handler for autosaving forms.
   */
  public function autosaveFormSubmit($form, FormStateInterface $form_state) {
    // As this processing might take some time we want to prevent that if the
    // connection is terminated the user input will be lost.
    ignore_user_abort(TRUE);
    if (!$this->isAutosaveSubmitValid($form_state)) {
      $form_state->disableCache();
      return;
    }
    // Having an autosave form session id also ensures that after resuming
    // editing the new autosaved entities will be saved to the same autosave
    // session id.
    $silverback_autosave_session_id = $this->getAutosaveFormSessionID($form_state);
    $current_user_id = $this->currentUser->id();
    $autosaved_form_state = $this->getLastAutosavedFormState($form_state, $silverback_autosave_session_id, $current_user_id);
    // If there is non-autosaved state for this session then we have to put the
    // user input into a temporary store and on each autosave submit compare
    // against it for changes and after the first change compare with the last
    // autosaved state.
    if (is_null($autosaved_form_state)) {
      if ($initial_user_input = $this->keyValueExpirableFactory->get('silverback_autosave')->get($silverback_autosave_session_id)) {
        $autosaved_form_state_input = $initial_user_input;
      }
      else {
        // 6 hours cache life time for forms should be plenty, like the form
        // cache.
        $expire = 21600;
        $this->keyValueExpirableFactory->get('silverback_autosave')->setWithExpire($silverback_autosave_session_id, $form_state->getUserInput(), $expire);

        // This is the first where we cache the user input initially and we are
        // done.
        $form_state->disableCache();
        return;
      }
    }
    else {
      $autosaved_form_state_input = $autosaved_form_state->getUserInput();
    }

    // Subsequent autosaving - compare the user input only. This should be
    // sufficient to detect changes in the fields.
    $form_state_input = $form_state->getUserInput();

    $skip_from_comparison_keys = [
      'form_build_id',
      'form_token',
      'ajax_page_state',
      'silverback_autosave_last_autosave_timestamp',
    ];
    foreach ($skip_from_comparison_keys as $skip_from_comparison_key) {
      unset($autosaved_form_state_input[$skip_from_comparison_key]);
      unset($form_state_input[$skip_from_comparison_key]);
    }

    $store = $autosaved_form_state_input != $form_state_input;

    if ($store) {
      $autosave_timestamp = $this->time->getRequestTime();
      $form_state->set('silverback_autosave_last_autosave_timestamp', $autosave_timestamp);
      $form_state->setTemporaryValue('silverback_autosave_last_autosave_timestamp', $autosave_timestamp);

      $this->storeState($form_state, $silverback_autosave_session_id, $autosave_timestamp, $current_user_id);
      $this->keyValueExpirableFactory->get('silverback_autosave')->delete($silverback_autosave_session_id);
    }

    // We don't have to cache the form each time an autosave submission is
    // triggered, especially when we've skipped the form validation.
    $form_state->disableCache();
  }

  /**
   * Ajax callback for autosaving forms.
   */
  public function autosaveFormAjax($form, FormStateInterface $form_state) {
    $response = new AjaxResponse();

    $timestamp = $form_state->getTemporaryValue('silverback_autosave_last_autosave_timestamp');
    if (is_numeric($timestamp)) {
      $response->addCommand(new InvokeCommand('input[name="silverback_autosave_last_autosave_timestamp"]', 'attr', ['value', $timestamp]));
    }

    return $response;
  }

  /**
   * Retrieves the autosave form session ID.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return string|null
   *   The autosave form session ID or NULL if none present yet.
   */
  protected static function getAutosaveFormSessionID(FormStateInterface $form_state) {
    return $form_state->get('silverback_autosave_session_id');
  }

  /**
   * Sets the autosave form session ID into the form state.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param string $silverback_autosave_session_id
   *   The autosave form session ID.
   */
  protected function setAutosaveFormSessionID(FormStateInterface $form_state, $silverback_autosave_session_id) {
    $form_state->set('silverback_autosave_session_id', $silverback_autosave_session_id);
  }

  /**
   * Returns the HTTP method used by the request that is building the form.
   *
   * @return string
   *   Can be any valid HTTP method, such as GET, POST, HEAD, etc.
   */
  protected function getRequestMethod() {
    return \Drupal::requestStack()->getCurrentRequest()->getMethod();
  }

}
