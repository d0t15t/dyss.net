<?php

namespace Drupal\persistent_player\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configures the Persistent Player module.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'persistent_player_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['persistent_player.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['content_selector'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Content selector'),
      '#description' => $this->t('The CSS selector of the element whose contents same-site navigation is allowed to replace. Everything outside this element (header, footer, the player itself) is left untouched, so a playing item keeps playing across pages. The active theme must render an element matching this selector on every page.'),
      '#required' => TRUE,
      '#config_target' => 'persistent_player.settings:content_selector',
    ];
    $form['enabled_pjax_nav'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable same-site AJAX navigation'),
      '#description' => $this->t('When disabled, the player still works, but internal links perform normal full-page navigations (which will interrupt playback). Disable this if the site already has its own client-side routing.'),
      '#config_target' => 'persistent_player.settings:enabled_pjax_nav',
    ];
    $form['excluded_path_patterns'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Excluded path patterns'),
      '#description' => $this->t('One path per line. Links matching these patterns (Drupal path-matching syntax, e.g. %wildcard) always perform a normal navigation. Administration and edit/delete routes should usually stay excluded.', ['%wildcard' => '/admin*']),
      '#config_target' => new ConfigTarget(
        'persistent_player.settings',
        'excluded_path_patterns',
        fromConfig: fn (array $value) => implode("\n", $value),
        toConfig: fn (string $value) => array_values(array_filter(array_map('trim', explode("\n", $value)))),
      ),
    ];

    return parent::buildForm($form, $form_state);
  }

}
