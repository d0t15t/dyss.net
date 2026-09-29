<?php

namespace Drupal\ai_sanitize\Form;

use Drupal\ai_sanitize\Detector\DetectorManager;
use Drupal\ai_sanitize\PlaceholderMap;
use Drupal\ai_sanitize\Sanitizer;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings for AI Sanitize, with a preview of what the AI would receive.
 */
class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected readonly DetectorManager $detectorManager,
    protected readonly Sanitizer $sanitizer,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('plugin.manager.ai_sanitize.detector'),
      $container->get('ai_sanitize.sanitizer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ai_sanitize_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ai_sanitize.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ai_sanitize.settings');

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Sensitive values are replaced with placeholders such as <code>[IBAN_1]</code> in every request the AI module sends to a provider: prompts, conversation history, tool results and tool-call arguments. The same value always gets the same placeholder, so the AI can still refer to it. Values are never logged.') . '</p>',
    ];

    $options = [];
    foreach ($this->detectorManager->getDefinitions() as $id => $definition) {
      $options[$id] = $this->t('<strong>@label</strong> → <code>[@type_n]</code><br><small>@description</small>', [
        '@label' => $definition['label'],
        '@type' => $definition['placeholder'],
        '@description' => $definition['description'] ?? '',
      ]);
    }
    $form['enabled_detectors'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Replace'),
      '#options' => $options,
      '#default_value' => $config->get('enabled_detectors') ?? [],
    ];

    $form['always_redact'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Always replace these exact values'),
      '#description' => $this->t('One per line, matched case-insensitively anywhere in the text, e.g. a customer number or a birth date in a known format. Replaced as <code>[REDACTED_n]</code>.'),
      '#default_value' => implode("\n", $config->get('always_redact') ?? []),
      '#rows' => 4,
    ];

    $form['custom_patterns'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Custom patterns'),
      '#description' => $this->t('One per line as <code>TYPE|/pattern/flags</code>, e.g. <code>CUSTOMER_NO|/\bK-\d{6}\b/</code>. The whole match is replaced with <code>[TYPE_n]</code>.'),
      '#default_value' => implode("\n", array_map(fn($p) => $p['label'] . '|' . $p['pattern'], $config->get('custom_patterns') ?? [])),
      '#rows' => 4,
    ];

    $form['allowlist'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Never replace'),
      '#description' => $this->t('One value per line that the AI may see even if a detector matches it, e.g. a shared service e-mail address. Spaces, dots and dashes are ignored when comparing.'),
      '#default_value' => implode("\n", $config->get('allowlist') ?? []),
      '#rows' => 3,
    ];

    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced'),
    ];
    $form['advanced']['placeholder_format'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Placeholder format'),
      '#description' => $this->t('<code>@type</code> is the type (e.g. IBAN), <code>@n</code> the number.'),
      '#default_value' => $config->get('placeholder_format') ?: '[@type_@n]',
      '#required' => TRUE,
    ];
    $form['advanced']['operation_types'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Only these operation types'),
      '#description' => $this->t('Comma-separated AI operation types, e.g. <code>chat, embeddings</code>. Leave empty to sanitize all of them (recommended).'),
      '#default_value' => implode(', ', $config->get('operation_types') ?? []),
    ];
    $form['advanced']['bypass_tags'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Skip requests with these tags'),
      '#description' => $this->t('Comma-separated request tags for trusted, self-hosted providers that may see the raw data.'),
      '#default_value' => implode(', ', $config->get('bypass_tags') ?? []),
    ];
    $form['advanced']['log_counts'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Log how many values were replaced per request'),
      '#description' => $this->t('Only counts and types are logged, never the values.'),
      '#default_value' => (bool) $config->get('log_counts'),
    ];

    $form['preview'] = [
      '#type' => 'details',
      '#title' => $this->t('Try it'),
      '#open' => (bool) $form_state->get('preview_result'),
    ];
    $form['preview']['preview_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Sample text'),
      '#description' => $this->t('Uses the saved settings. Nothing is stored or sent anywhere.'),
      '#rows' => 5,
    ];
    $form['preview']['preview_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Show what the AI would receive'),
      '#submit' => ['::previewSubmit'],
      '#limit_validation_errors' => [['preview_text']],
    ];
    if ($result = $form_state->get('preview_result')) {
      $form['preview']['result'] = [
        '#type' => 'html_tag',
        '#tag' => 'pre',
        '#value' => $result,
        '#attributes' => ['style' => 'white-space: pre-wrap'],
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * Shows the sanitized sample text.
   */
  public function previewSubmit(array &$form, FormStateInterface $form_state): void {
    // A fresh map, so numbering starts at 1 for every preview.
    $format = $this->config('ai_sanitize.settings')->get('placeholder_format') ?: '[@type_@n]';
    $text = (string) $form_state->getValue('preview_text');
    $result = $this->sanitizer->sanitize($text, new PlaceholderMap($format));
    $form_state->set('preview_result', $result->text);
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->getTriggeringElement()['#id'] === 'edit-preview-submit') {
      return;
    }
    foreach ($this->parseCustomPatterns((string) $form_state->getValue('custom_patterns')) as $i => $item) {
      if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $item['label'])) {
        $form_state->setErrorByName('custom_patterns', $this->t('Line @n: the type must be uppercase letters, digits and underscores, e.g. CUSTOMER_NO.', ['@n' => $i + 1]));
      }
      elseif (@preg_match($item['pattern'], '') === FALSE) {
        $form_state->setErrorByName('custom_patterns', $this->t('Line @n: %pattern is not a valid regular expression.', [
          '@n' => $i + 1,
          '%pattern' => $item['pattern'],
        ]));
      }
    }
    $format = (string) $form_state->getValue('placeholder_format');
    if (!str_contains($format, '@type') || !str_contains($format, '@n')) {
      $form_state->setErrorByName('placeholder_format', $this->t('The placeholder format must contain @type and @n.'));
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $lines = fn(string $key) => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $form_state->getValue($key)))));
    $csv = fn(string $key) => array_values(array_filter(array_map('trim', explode(',', (string) $form_state->getValue($key)))));
    $this->config('ai_sanitize.settings')
      ->set('enabled_detectors', array_values(array_filter($form_state->getValue('enabled_detectors'))))
      ->set('always_redact', $lines('always_redact'))
      ->set('custom_patterns', $this->parseCustomPatterns((string) $form_state->getValue('custom_patterns')))
      ->set('allowlist', $lines('allowlist'))
      ->set('placeholder_format', trim((string) $form_state->getValue('placeholder_format')))
      ->set('operation_types', $csv('operation_types'))
      ->set('bypass_tags', $csv('bypass_tags'))
      ->set('log_counts', (bool) $form_state->getValue('log_counts'))
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Parses "TYPE|/pattern/" lines.
   *
   * @return array<int, array{label: string, pattern: string}>
   *   The patterns.
   */
  protected function parseCustomPatterns(string $value): array {
    $patterns = [];
    foreach (array_filter(array_map('trim', preg_split('/\R/', $value))) as $line) {
      [$label, $pattern] = array_pad(explode('|', $line, 2), 2, '');
      $patterns[] = ['label' => trim($label), 'pattern' => trim($pattern)];
    }
    return $patterns;
  }

}
