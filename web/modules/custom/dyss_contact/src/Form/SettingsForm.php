<?php

namespace Drupal\dyss_contact\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Sets the address contact form messages are sent to.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'dyss_contact_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['dyss_contact.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['recipient'] = [
      '#type' => 'email',
      '#title' => $this->t('Recipient'),
      '#description' => $this->t('Messages from the <a href=":url">contact form</a> are sent to this address.', [':url' => Url::fromRoute('dyss_contact.form')->toString()]),
      '#required' => TRUE,
      '#config_target' => 'dyss_contact.settings:recipient',
    ];
    return parent::buildForm($form, $form_state);
  }

}
