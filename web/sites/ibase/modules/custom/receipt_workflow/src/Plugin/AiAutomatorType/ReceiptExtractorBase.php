<?php

declare(strict_types=1);

namespace Drupal\receipt_workflow\Plugin\AiAutomatorType;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Service\AiProviderFormHelper;
use Drupal\ai\Service\PromptJsonDecoder\PromptJsonDecoderInterface;
use Drupal\ai_automators\PluginBaseClasses\RuleBase;

/**
 * Base class for automators that extract structured data from receipt OCR text.
 *
 * Subclasses target individual field types (string, decimal, datetime, taxonomy
 * reference) on node.receipt. The OCR text lives directly on the node in
 * field_receipt_text. The base field the admin configures should be
 * field_receipt_text (string_long).
 *
 * Prompt template convention: use [receipt_text] as the placeholder where the
 * full OCR text should be inserted.
 */
abstract class ReceiptExtractorBase extends RuleBase {

  public function __construct(
    AiProviderPluginManager $pluginManager,
    AiProviderFormHelper $formHelper,
    PromptJsonDecoderInterface $promptJsonDecoder,
  ) {
    parent::__construct($pluginManager, $formHelper, $promptJsonDecoder);
  }

  /**
   * {@inheritdoc}
   *
   * The base field should be field_receipt_text (string_long).
   */
  public function allowedInputs(): array {
    return ['string_long'];
  }

  /**
   * {@inheritdoc}
   *
   * Only run when the node has OCR text.
   */
  public function ruleIsAllowed(ContentEntityInterface $entity, FieldDefinitionInterface $fieldDefinition): bool {
    return $this->getReceiptText($entity) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function generate(ContentEntityInterface $entity, FieldDefinitionInterface $fieldDefinition, array $automatorConfig): array {
    $receiptText = $this->getReceiptText($entity);
    if ($receiptText === NULL) {
      return [];
    }

    $prompt = str_replace('[receipt_text]', $receiptText, $automatorConfig['prompt'] ?? '');
    if (empty(trim($prompt))) {
      return [];
    }

    $instance = $this->prepareLlmInstance('chat', $automatorConfig);
    $raw = trim($this->runRawChatMessage($prompt, $automatorConfig, $instance, $entity)->getText());

    if ($raw === '' || strtolower($raw) === 'null' || strtolower($raw) === 'n/a' || strtolower($raw) === 'unknown') {
      return [];
    }

    $value = $this->parseValue($raw, $entity, $fieldDefinition, $automatorConfig);
    return $value !== NULL ? [$value] : [];
  }

  /**
   * Parse the raw LLM text response into the value format expected by the field.
   *
   * Return NULL to skip storing (e.g. when parsing fails).
   */
  abstract protected function parseValue(string $raw, ContentEntityInterface $entity, FieldDefinitionInterface $fieldDefinition, array $automatorConfig): mixed;

  /**
   * Returns the OCR text directly from the receipt node's field_receipt_text.
   */
  protected function getReceiptText(ContentEntityInterface $entity): ?string {
    if (!$entity->hasField('field_receipt_text') || $entity->get('field_receipt_text')->isEmpty()) {
      return NULL;
    }
    $text = $entity->get('field_receipt_text')->value;
    return ($text !== NULL && trim($text) !== '') ? $text : NULL;
  }

}
