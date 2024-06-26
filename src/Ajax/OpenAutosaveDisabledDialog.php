<?php

namespace Drupal\silverback_autosave\Ajax;

use Drupal\Core\Ajax\OpenModalDialogCommand;

/**
 * Defines an AJAX command to open a notification in modal dialog.
 *
 * @ingroup ajax
 */
class OpenAutosaveDisabledDialog extends OpenModalDialogCommand {

  /**
   * {@inheritdoc}
   */
  public function render() {
    return ['command' => 'openAutosaveDisabledDialog'] + parent::render();
  }

}
