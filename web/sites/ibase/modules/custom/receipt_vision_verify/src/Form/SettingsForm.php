<?php

declare(strict_types=1);

namespace Drupal\receipt_vision_verify\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Enum\AiModelCapability;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings form for Receipt Vision Verify.
 */
class SettingsForm extends ConfigFormBase {

  public function __construct(
    protected AiProviderPluginManager $aiProvider,
  ) {}

  public static function create(ContainerInterface $container): static {
    $instance = new static($container->get('ai.provider'));
    $instance->configFactory = $container->get('config.factory');
    $instance->typedConfigManager = $container->get('config.typed');
    return $instance;
  }

  public function getFormId(): string {
    return 'receipt_vision_verify_settings';
  }

  protected function getEditableConfigNames(): array {
    return ['receipt_vision_verify.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('receipt_vision_verify.settings');

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable vision verification'),
      '#description' => $this->t('When disabled, the vision extraction automator will not run. Use as a kill switch if vision costs spike.'),
      '#default_value' => $config->get('enabled'),
    ];

    // Vision provider + model. Vision is a *capability* of the chat operation
    // type, not a distinct operation type — query chat providers that declare
    // the ChatWithImageVision capability.
    $providerOptions = [];
    $visionProviders = $this->aiProvider->getProvidersForOperationType(
      'chat',
      TRUE,
      [AiModelCapability::ChatWithImageVision],
    );
    foreach ($visionProviders as $id => $provider) {
      $providerOptions[$id] = $provider['label'] ?? $id;
    }

    if (empty($providerOptions)) {
      $this->messenger()->addWarning($this->t(
        'No configured AI providers expose a vision-capable chat model. Check your provider settings at <a href=":url">AI settings</a>.',
        [':url' => '/admin/config/ai']
      ));
    }

    $form['ai_provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Vision AI provider'),
      '#options' => ['' => $this->t('- Use site default -')] + $providerOptions,
      '#default_value' => $config->get('ai_provider'),
    ];

    $form['ai_model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Vision AI model'),
      '#description' => $this->t('Model ID (e.g. gpt-4o, claude-3-5-sonnet-20241022). Leave empty to use the provider default.'),
      '#default_value' => $config->get('ai_model'),
    ];

    $form['auto_approve_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Auto-approve confidence threshold'),
      '#description' => $this->t('Receipts scoring at or above this threshold skip manual review and go straight to Approved. Range: 0-100.'),
      '#default_value' => $config->get('auto_approve_threshold'),
      '#min' => 0,
      '#max' => 100,
    ];

    $form['image_dpi'] = [
      '#type' => 'number',
      '#title' => $this->t('Image render DPI'),
      '#description' => $this->t('Higher DPI = better OCR of small text, more vision tokens, higher cost. 150-300 is typical.'),
      '#default_value' => $config->get('image_dpi'),
      '#min' => 72,
      '#max' => 600,
    ];

    $form['field_weights'] = [
      '#type' => 'details',
      '#title' => $this->t('Field importance weights'),
      '#description' => $this->t('How much each field contributes to the overall confidence score. Values should sum to 100.'),
      '#open' => FALSE,
      '#tree' => TRUE,
    ];

    $weightLabels = [
      'field_total_amount' => $this->t('Total amount'),
      'field_receipt_date' => $this->t('Receipt date'),
      'field_vendor' => $this->t('Vendor'),
      'field_currency' => $this->t('Currency'),
      'field_subtotal_amount' => $this->t('Subtotal'),
      'field_tax_amount' => $this->t('Tax amount'),
      'field_receipt_number' => $this->t('Receipt number'),
      'field_payment_method' => $this->t('Payment method'),
      'field_expense_category' => $this->t('Expense category'),
    ];

    $weights = $config->get('field_weights') ?? [];
    foreach ($weightLabels as $key => $label) {
      $form['field_weights'][$key] = [
        '#type' => 'number',
        '#title' => $label,
        '#default_value' => $weights[$key] ?? 0,
        '#min' => 0,
        '#max' => 100,
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $weights = $form_state->getValue('field_weights') ?? [];
    $sum = array_sum(array_map('intval', $weights));
    if ($sum !== 100) {
      $form_state->setErrorByName(
        'field_weights',
        $this->t('Field weights must sum to 100 (currently: @sum).', ['@sum' => $sum])
      );
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('receipt_vision_verify.settings');
    foreach (['enabled', 'ai_provider', 'ai_model', 'auto_approve_threshold', 'image_dpi', 'field_weights'] as $key) {
      $config->set($key, $form_state->getValue($key));
    }
    $config->save();
    parent::submitForm($form, $form_state);
  }

}
