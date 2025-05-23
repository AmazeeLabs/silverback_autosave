<?php

namespace Drupal\silverback_autosave\Storage;

use Drupal\Component\Serialization\SerializationInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

/**
 * A database backend for autosave of entity forms.
 */
class AutosaveEntityFormDatabaseStorage implements AutosaveEntityFormStorageInterface {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The serializer to use.
   *
   * @var \Drupal\Component\Serialization\ObjectAwareSerializationInterface
   */
  protected $serializer;

  /**
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs an AutosaveEntityStorage.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param \Drupal\Component\Serialization\SerializationInterface $serializer
   *   The serializer to use.
   */
  public function __construct(Connection $connection, SerializationInterface $serializer, ClientInterface $httpClient, LoggerChannelInterface $logger) {
    $this->connection = $connection;
    $this->serializer = $serializer;
    $this->httpClient = $httpClient;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public function storeEntityAndFormState($form_id, $form_session_id, $entity_type_id, $entity_id, $langcode, $uid, $timestamp, EntityInterface $entity, FormStateInterface $form_state) {
    $serialized_entity = $this->serializeEntity($entity, $form_state);
    $serialized_form_state = $this->serializer->encode([
      'storage' => $form_state->getStorage(),
      'input' => $form_state->getUserInput(),
    ]);

    if (!$entity->isNew()) {

      // @todo FIX This causes new previews to restore the saved one (instead of current form state).
      // Purge previous stored states for the entity,
      // e.g. when user is navigating away from the page.
      // Also, this approach ensures that there will be always
      // a stored form state in the db, in case we need to restore.
      // $this->purgeAutosavedEntityState($entity_type_id, $entity_id, NULL, $form_id, $langcode, $uid);
      $this->connection->insert(static::AUTOSAVE_ENTITY_FORM_TABLE)
        ->fields([
          'form_id',
          'form_session_id',
          'entity_type_id',
          'entity_id',
          'langcode',
          'uid',
          'timestamp',
          'entity',
          'form_state',
        ])
        ->values([
          $form_id,
          $form_session_id,
          $entity_type_id,
          $entity_id,
          $langcode,
          $uid,
          $timestamp,
          $serialized_entity,
          $serialized_form_state,
        ])
        ->execute();
      try {
        $this->httpClient->post((getenv('PREVIEW_URL') ?: 'http://localhost:8001') . '/__preview', [
          RequestOptions::HEADERS => [
            'Content-Type' => 'application/json',
          ],
          RequestOptions::JSON => [
            'entity_type_id' => $entity_type_id,
            'entity_id' => $entity_id,
            'langcode' => $langcode,
          ],
        ]);
      } catch (GuzzleException $exc) {
        $this->logger->critical('Error while to update preview.');
        $this->logger->critical($exc->getMessage());
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityAndFormState($form_id, $entity_type_id, $entity_id, $langcode, $uid = NULL, $form_session_id = NULL, $autosaved_timestamp = NULL) {
    $result = NULL;
    $query = $this->connection->select(static::AUTOSAVE_ENTITY_FORM_TABLE, 'cefa')
      ->fields('cefa', ['entity', 'form_state', 'timestamp'])
      ->orderBy('timestamp', 'DESC')
      ->condition('form_id', $form_id);

    if (isset($form_session_id)) {
      $query->condition('form_session_id', $form_session_id);
    }

    $query->condition('entity_type_id', $entity_type_id)
      ->condition('entity_id', $entity_id)
      ->condition('langcode', $langcode);

    if (isset($uid)) {
      $query->condition('uid', $uid);
    }

    if (isset($autosaved_timestamp)) {
      $query->condition('timestamp', $autosaved_timestamp);
    }

    $data = $query->execute()->fetchAssoc();

    if ($data) {
      // Prepare the entity object.
      $result['entity'] = $this->serializer->decode($data['entity']);
      // Prepare the form state.
      $form_state_data = $this->serializer->decode($data['form_state']);
      $result['form_state'] = new FormState();
      $result['form_state']->setStorage($form_state_data['storage']);
      $result['form_state']->setUserInput($form_state_data['input']);
      $result['form_state']->set('silverback_autosave_state_timestamp', $data['timestamp']);
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function getEntity($form_id, $entity_type_id, $entity_id, $langcode, $uid, $form_session_id = NULL, $autosaved_timestamp = NULL) {
    $entity = NULL;
    $query = $this->connection->select(static::AUTOSAVE_ENTITY_FORM_TABLE, 'cefa')
      ->fields('cefa', ['entity'])
      ->orderBy('timestamp', 'DESC')
      ->condition('form_id', $form_id);

    if (isset($form_session_id)) {
      $query->condition('form_session_id', $form_session_id);
    }

    $query->condition('entity_type_id', $entity_type_id)
      ->condition('entity_id', $entity_id)
      ->condition('langcode', $langcode)
      ->condition('uid', $uid);

    if (isset($autosaved_timestamp)) {
      $query->condition('timestamp', $autosaved_timestamp);
    }

    $data = $query->execute()->fetchAssoc();

    if ($data) {
      // Prepare the entity object.
      $entity = $this->serializer->decode($data['entity']);
    }

    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormState($form_id, $entity_type_id, $entity_id, $langcode, $uid, $form_session_id = NULL, $autosaved_timestamp = NULL) {
    $form_state = NULL;
    $query = $this->connection->select(static::AUTOSAVE_ENTITY_FORM_TABLE, 'cefa')
      ->fields('cefa', ['form_state', 'timestamp'])
      ->orderBy('timestamp', 'DESC')
      ->condition('form_id', $form_id);

    if (isset($form_session_id)) {
      $query->condition('form_session_id', $form_session_id);
    }

    $query->condition('entity_type_id', $entity_type_id)
      ->condition('entity_id', $entity_id)
      ->condition('langcode', $langcode)
      ->condition('uid', $uid);

    if (isset($autosaved_timestamp)) {
      $query->condition('timestamp', $autosaved_timestamp);
    }

    $data = $query->execute()->fetchAssoc();

    if ($data) {
      // Prepare the form state.
      $form_state_data = $this->serializer->decode($data['form_state']);
      $form_state = new FormState();
      $form_state->setStorage($form_state_data['storage']);
      $form_state->setUserInput($form_state_data['input']);
      $form_state->set('silverback_autosave_state_timestamp', $data['timestamp']);
    }

    return $form_state;
  }

  /**
   * {@inheritdoc}
   */
  public function hasAutosavedStateForFormState(FormStateInterface $form_state, $uid) {
    $result = FALSE;
    if (($form_object = $form_state->getFormObject()) && ($form_object instanceof EntityFormInterface) && ($entity = $form_object->getEntity()) && !$entity->isNew()) {
      $result = $this->hasAutosavedState($form_object->getFormId(), $entity->getEntityTypeId(), $entity->id(), $entity->language()->getId(), $uid, $form_state->get('silverback_autosave_session_id'));
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function hasAutosavedState($form_id, $entity_type_id, $entity_id, $langcode, $uid, $form_session_id = NULL) {
    $query = $this->connection->select(static::AUTOSAVE_ENTITY_FORM_TABLE, 'cefa')
      ->condition('form_id', $form_id);

    if (isset($form_session_id)) {
      $query->condition('form_session_id', $form_session_id);
    }

    $query->condition('entity_type_id', $entity_type_id)
      ->condition('entity_id', $entity_id)
      ->condition('langcode', $langcode)
      ->condition('uid', $uid);

    $count = $query->countQuery()
      ->execute()
      ->fetchField();
    return (bool) $count;
  }

  /**
   * {@inheritdoc}
   */
  public function getLastAutosavedStateTimestamp($form_id, $entity_type_id, $entity_id, $langcode, $uid) {
    $timestamp = $this->connection->select(static::AUTOSAVE_ENTITY_FORM_TABLE, 'cefa')
      ->fields('cefa', ['timestamp'])
      ->orderBy('timestamp', 'DESC')
      ->condition('form_id', $form_id)
      ->condition('entity_type_id', $entity_type_id)
      ->condition('entity_id', $entity_id)
      ->condition('langcode', $langcode)
      ->condition('uid', $uid)
      ->execute()
      ->fetchField();

    return is_bool($timestamp) ? NULL : $timestamp;
  }

  /**
   * {@inheritdoc}
   */
  public function purgeAutosavedEntityState($entity_type_id, $entity_id, $form_session_id = NULL, $form_id = NULL, $langcode = NULL, $uid = NULL) {
    $query = $this->connection->delete(static::AUTOSAVE_ENTITY_FORM_TABLE);

    if (isset($form_session_id)) {
      $query->condition('form_session_id', $form_session_id);
    }

    if (isset($form_id)) {
      $query->condition('form_id', $form_id);
    }

    $query->condition('entity_type_id', $entity_type_id)
      ->condition('entity_id', $entity_id);

    if (isset($langcode)) {
      $query->condition('langcode', $langcode);
    }

    if (isset($uid)) {
      $query->condition('uid', $uid);
    }

    $query->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function purgeAutosavedEntitiesStates($entity_type_id = NULL, $langcode = NULL, $uid = NULL) {
    $query = $this->connection->delete(static::AUTOSAVE_ENTITY_FORM_TABLE);
    if (isset($entity_type_id)) {
      $query->condition('entity_type_id', $entity_type_id);
    }
    if (isset($langcode)) {
      $query->condition('langcode', $langcode);
    }
    if (isset($uid)) {
      $query->condition('uid', $uid);
    }
    $query->execute();
  }

  /**
   * Returns the serialized entity object.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object to serialize.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state to retrieve meta information about the serialization.
   *
   * @return string
   *   The serialized entity.
   */
  protected function serializeEntity(EntityInterface $entity, FormStateInterface $form_state) {
    if (($form_object = $form_state->getFormObject()) && ($form_object instanceof ContentEntityFormInterface) && method_exists($form_object, 'isEntityDeepSerializationRequired') && $form_object->isEntityDeepSerializationRequired()) {
      /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
      if (method_exists($entity, 'setDeepSerialization')) {
        $entity->setDeepSerialization(TRUE);
      }
    }

    return $this->serializer->encode($entity);
  }

}
