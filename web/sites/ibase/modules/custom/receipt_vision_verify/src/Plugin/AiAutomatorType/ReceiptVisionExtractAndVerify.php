<?php

declare(strict_types=1);

namespace Drupal\receipt_vision_verify\Plugin\AiAutomatorType;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\Service\AiProviderFormHelper;
use Drupal\ai\Service\PromptJsonDecoder\PromptJsonDecoderInterface;
use Drupal\ai_automators\Attribute\AiAutomatorType;
use Drupal\ai_automators\PluginBaseClasses\RuleBase;
use Drupal\file\FileInterface;
use Drupal\receipt_vision_verify\Service\PdfToImage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * AI Automator that runs a vision pass over a receipt PDF.
 *
 * Reads the file from the configured base field (expected: field_receipt),
 * renders page 1 as an image, sends it to a vision-capable LLM with a
 * structured JSON extraction prompt, and stores the raw JSON response.
 */
#[AiAutomatorType(
  id: 'receipt_vision:extract_and_verify',
  label: new TranslatableMarkup('Receipt: Vision extraction + verify'),
  field_rule: 'string_long',
  target: '',
)]
final class ReceiptVisionExtractAndVerify extends RuleBase {

  /**
   * Map of receipt node field → controlled-vocabulary ID. Vision must pick
   * from these vocabularies' existing terms instead of inventing values.
   * Vendor is intentionally excluded — it's open-ended and may have many terms.
   */
  protected const CONSTRAINED_FIELDS = [
    'field_payment_method' => 'payment_method',
    'field_expense_category' => 'expense_category',
    'field_currency' => 'currency',
  ];

  public function __construct(
    AiProviderPluginManager $pluginManager,
    AiProviderFormHelper $formHelper,
    PromptJsonDecoderInterface $promptJsonDecoder,
    private readonly PdfToImage $pdfToImage,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($pluginManager, $formHelper, $promptJsonDecoder);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $container->get('ai.provider'),
      $container->get('ai.form_helper'),
      $container->get('ai.prompt_json_decode'),
      $container->get('receipt_vision_verify.pdf_to_image'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
    );
  }

  public function allowedInputs(): array {
    return ['file'];
  }

  public function helpText(): string {
    return 'Sends the receipt image to a vision AI and extracts all receipt fields as JSON. Base field should be field_receipt (the PDF).';
  }

  public function placeholderText(): string {
    return $this->defaultPrompt();
  }

  public function ruleIsAllowed(ContentEntityInterface $entity, FieldDefinitionInterface $fieldDefinition): bool {
    if (!$this->configFactory->get('receipt_vision_verify.settings')->get('enabled')) {
      return FALSE;
    }
    return $entity->hasField('field_receipt') && !$entity->get('field_receipt')->isEmpty();
  }

  public function generate(ContentEntityInterface $entity, FieldDefinitionInterface $fieldDefinition, array $automatorConfig): array {
    $pdfFile = $entity->get('field_receipt')->entity;
    if (!$pdfFile instanceof FileInterface) {
      return [];
    }

    $imageFile = $this->pdfToImage->renderPdf($pdfFile);
    if (!$imageFile) {
      return [];
    }

    // Build the chat input with image + prompt.
    $prompt = $automatorConfig['prompt'] ?? '';
    if (trim($prompt) === '') {
      $prompt = $this->defaultPrompt();
    }

    // Append a Constraints section listing allowed taxonomy values, so the
    // vision model picks from the same vocabularies the OCR text-extractor
    // uses. This eliminates spurious mismatches like "MASTERCARD" vs
    // "Credit Card" or "Entertainment" vs "Meals".
    $prompt .= "\n\n" . $this->buildConstraintsSection();

    $message = new ChatMessage('user', $prompt);
    $message->setImageFromFile($imageFile);
    $input = new ChatInput([$message]);

    // Resolve provider/model from module settings first, fall back to automator config.
    $settings = $this->configFactory->get('receipt_vision_verify.settings');
    $configuredProvider = $settings->get('ai_provider');
    $configuredModel = $settings->get('ai_model');

    if ($configuredProvider) {
      $instance = $this->aiPluginManager->createInstance($configuredProvider);
      $model = $configuredModel ?: '';
    }
    else {
      $instance = $this->prepareLlmInstance('chat', $automatorConfig);
      $model = $this->getModel($automatorConfig);
    }

    try {
      $response = $instance->chat($input, $model, ['receipt_vision_verify']);
    }
    catch (\Exception $e) {
      \Drupal::logger('receipt_vision_verify')->error(
        'Vision chat failed: @msg', ['@msg' => $e->getMessage()],
      );
      return [];
    }

    $raw = trim($response->getNormalized()->getText());
    if ($raw === '') {
      return [];
    }

    // Strip code fences if the model wrapped JSON in ```json ... ```
    $raw = preg_replace('/^```(?:json)?\s*/m', '', $raw);
    $raw = preg_replace('/\s*```\s*$/m', '', $raw);
    $raw = trim($raw);

    // Validate it's JSON — if not, log and bail.
    $decoded = json_decode($raw, TRUE);
    if (!is_array($decoded)) {
      \Drupal::logger('receipt_vision_verify')->warning(
        'Vision response was not valid JSON: @raw', ['@raw' => substr($raw, 0, 500)],
      );
      return [];
    }

    // Re-encode for clean storage.
    return [json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
  }

  public function verifyValue(ContentEntityInterface $entity, $value, FieldDefinitionInterface $fieldDefinition, array $automatorConfig): bool {
    return is_string($value) && json_decode($value, TRUE) !== NULL;
  }

  public function storeValues(ContentEntityInterface $entity, array $values, FieldDefinitionInterface $fieldDefinition, array $automatorConfig): bool {
    $entity->set($fieldDefinition->getName(), $values[0]);
    return TRUE;
  }

  /**
   * Build a constraints block listing allowed taxonomy values per field.
   *
   * The vision model is instructed to pick from these lists so that its
   * output matches the controlled vocabulary (and therefore matches what
   * the OCR text-extractor produces).
   */
  private function buildConstraintsSection(): string {
    $lines = ['Constraints — for the fields below, you MUST pick the closest item from the provided list. Never invent new values for these fields. If no item fits, return null.'];

    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    foreach (self::CONSTRAINED_FIELDS as $fieldName => $vid) {
      $tids = $termStorage->getQuery()
        ->condition('vid', $vid)
        ->accessCheck(FALSE)
        ->execute();
      if (empty($tids)) {
        continue;
      }
      $names = [];
      foreach ($termStorage->loadMultiple($tids) as $term) {
        $names[] = $term->getName();
      }
      // Map back to the JSON key the model produces (strip "field_" prefix).
      $jsonKey = preg_replace('/^field_/', '', $fieldName);
      $lines[] = sprintf('- "%s": pick exactly one of: %s', $jsonKey, implode(', ', $names));
    }

    return implode("\n", $lines);
  }

  private function defaultPrompt(): string {
    return <<<PROMPT
Analyze this receipt image and extract the following fields as a single JSON object.
If a field cannot be determined from the image, return null for that field.

{
  "vendor": "string — merchant or business name",
  "receipt_number": "string — receipt or invoice number",
  "date": "string in YYYY-MM-DD format",
  "total_amount": "number — final total",
  "subtotal_amount": "number — before tax",
  "tax_amount": "number — tax/VAT",
  "currency": "string — ISO code (USD, EUR, etc.)",
  "payment_method": "string — credit card, cash, etc.",
  "expense_category": "string — best-fit category"
}

Return ONLY the JSON object. No markdown fences, no explanation, no commentary.
PROMPT;
  }

}
