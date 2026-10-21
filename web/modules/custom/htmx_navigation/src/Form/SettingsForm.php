<?php

namespace Drupal\htmx_navigation\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configures htmx navigation.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'htmx_navigation_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['htmx_navigation.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['content_selector'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Content selector'),
      '#description' => $this->t('The CSS selector of the element whose contents same-site navigation is allowed to replace. Everything outside this element (header, footer, a media player) is left untouched, so e.g. a playing item keeps playing across pages. The active theme must render an element matching this selector on every page.'),
      '#required' => TRUE,
      '#config_target' => 'htmx_navigation.settings:content_selector',
    ];
    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable same-site AJAX navigation'),
      '#description' => $this->t('When disabled, internal links perform normal full-page navigations (which, for example, interrupt a playing media player).'),
      '#config_target' => 'htmx_navigation.settings:enabled',
    ];
    $form['excluded_path_patterns'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Excluded path patterns'),
      '#description' => $this->t('One path per line. Links matching these patterns (Drupal path-matching syntax, e.g. %wildcard) always perform a normal navigation. Administration and edit/delete routes should usually stay excluded.', ['%wildcard' => '/admin*']),
      '#config_target' => new ConfigTarget(
        'htmx_navigation.settings',
        'excluded_path_patterns',
        fromConfig: fn (array $value) => implode("\n", $value),
        toConfig: fn (string $value) => array_values(array_filter(array_map('trim', explode("\n", $value)))),
      ),
    ];

    return parent::buildForm($form, $form_state);
  }

}
