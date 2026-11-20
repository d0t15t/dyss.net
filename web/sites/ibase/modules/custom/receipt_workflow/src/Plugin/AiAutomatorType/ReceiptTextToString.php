<?php

declare(strict_types=1);

namespace Drupal\receipt_workflow\Plugin\AiAutomatorType;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_automators\Attribute\AiAutomatorType;

/**
 * Extracts a plain string value from receipt OCR text.
 *
 * Use on field_receipt_number. Example prompt:
 *   "Extract the receipt or invoice number from the following receipt text.
 *    Return ONLY the number/code, nothing else. If not found, return null.
 *
 *    [receipt_text]"
 */
#[AiAutomatorType(
  id: 'receipt:text_to_string',
  label: new TranslatableMarkup('Receipt: Extract String Field'),
  field_rule: 'string',
  target: '',
)]
final class ReceiptTextToString extends ReceiptExtractorBase {

  /**
   * {@inheritdoc}
   */
  public function helpText(): string {
    return 'Extracts a plain string value (e.g., receipt number) from OCR text of the linked receipt media. Use [receipt_text] in the prompt as a placeholder for the OCR content.';
  }

  /**
   * {@inheritdoc}
   */
  public function placeholderText(): string {
    return "Extract the receipt number from the following receipt text.\nReturn ONLY the number/code, nothing else. If not found, return null.\n\n[receipt_text]";
  }

  /**
   * {@inheritdoc}
   */
  protected function parseValue(string $raw, ContentEntityInterface $entity, FieldDefinitionInterface $fieldDefinition, array $automatorConfig): mixed {
    // Trim to the max length of the string field (default 255).
    $maxLength = (int) ($fieldDefinition->getSetting('max_length') ?? 255);
    return mb_substr($raw, 0, $maxLength);
  }

  /**
   * {@inheritdoc}
   */
  public function verifyValue(ContentEntityInterface $entity, $value, FieldDefinitionInterface $fieldDefinition, array $automatorConfig): bool {
    return is_string($value) && trim($value) !== '';
  }

  /**
   * {@inheritdoc}
   */
  public function storeValues(ContentEntityInterface $entity, array $values, FieldDefinitionInterface $fieldDefinition, array $automatorConfig): bool {
    $entity->set($fieldDefinition->getName(), $values[0]);
    return TRUE;
  }

}
