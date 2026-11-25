<?php

declare(strict_types=1);

namespace Drupal\receipt_workflow\Plugin\AiAutomatorType;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_automators\Attribute\AiAutomatorType;

/**
 * Extracts a date from receipt OCR text and stores it as a datetime field.
 *
 * Use on field_receipt_date. Example prompt:
 *   "Extract the purchase date from the following receipt.
 *    Return ONLY the date in ISO 8601 format (YYYY-MM-DD). If not found,
 *    return null.
 *
 *    [receipt_text]"
 */
#[AiAutomatorType(
  id: 'receipt:text_to_datetime',
  label: new TranslatableMarkup('Receipt: Extract Date'),
  field_rule: 'datetime',
  target: '',
)]
final class ReceiptTextToDatetime extends ReceiptExtractorBase {

  /**
   * {@inheritdoc}
   */
  public function helpText(): string {
    return 'Extracts a date from OCR text of the linked receipt media and stores it in a datetime field. Instruct the LLM to return ISO 8601 (YYYY-MM-DD). Use [receipt_text] as a placeholder.';
  }

  /**
   * {@inheritdoc}
   */
  public function placeholderText(): string {
    return "Extract the purchase date from the following receipt.\nReturn ONLY the date in ISO 8601 format (YYYY-MM-DD). If not found, return null.\n\n[receipt_text]";
  }

  /**
   * {@inheritdoc}
   */
  protected function parseValue(string $raw, ContentEntityInterface $entity, FieldDefinitionInterface $fieldDefinition, array $automatorConfig): mixed {
    // Accept YYYY-MM-DD or try to parse with strtotime.
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
      return $raw . 'T00:00:00';
    }
    $ts = strtotime($raw);
    if ($ts === FALSE) {
      return NULL;
    }
    return date('Y-m-d\T00:00:00', $ts);
  }

  /**
   * {@inheritdoc}
   */
  public function verifyValue(ContentEntityInterface $entity, $value, FieldDefinitionInterface $fieldDefinition, array $automatorConfig): bool {
    return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $value) === 1;
  }

  /**
   * {@inheritdoc}
   */
  public function storeValues(ContentEntityInterface $entity, array $values, FieldDefinitionInterface $fieldDefinition, array $automatorConfig): bool {
    $entity->set($fieldDefinition->getName(), ['value' => $values[0]]);
    return TRUE;
  }

}
