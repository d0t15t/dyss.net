<?php

declare(strict_types=1);

namespace Drupal\receipt_workflow\Plugin\AiAutomatorType;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_automators\Attribute\AiAutomatorType;

/**
 * Extracts a decimal numeric value from receipt OCR text.
 *
 * Use on field_total_amount, field_subtotal_amount, field_tax_amount.
 * Example prompt for total amount:
 *   "Extract the total amount from the following receipt.
 *    Return ONLY the number without any currency symbol, spaces, or commas
 *    (e.g. 12.50). If not found, return null.
 *
 *    [receipt_text]"
 */
#[AiAutomatorType(
  id: 'receipt:text_to_decimal',
  label: new TranslatableMarkup('Receipt: Extract Decimal Amount'),
  field_rule: 'decimal',
  target: '',
)]
final class ReceiptTextToDecimal extends ReceiptExtractorBase {

  /**
   * {@inheritdoc}
   */
  public function helpText(): string {
    return 'Extracts a decimal number (e.g., total, subtotal, or tax amount) from OCR text of the linked receipt media. Use [receipt_text] as a placeholder for the OCR content.';
  }

  /**
   * {@inheritdoc}
   */
  public function placeholderText(): string {
    return "Extract the total amount from the following receipt.\nReturn ONLY the number without any currency symbol, spaces, or commas (e.g. 12.50). If not found, return null.\n\n[receipt_text]";
  }

  /**
   * {@inheritdoc}
   */
  protected function parseValue(string $raw, ContentEntityInterface $entity, FieldDefinitionInterface $fieldDefinition, array $automatorConfig): mixed {
    // Strip anything that isn't a digit, dot, or minus sign.
    $cleaned = preg_replace('/[^0-9.\-]/', '', $raw);
    if ($cleaned === '' || $cleaned === '-') {
      return NULL;
    }
    $float = (float) $cleaned;
    return is_nan($float) ? NULL : $float;
  }

  /**
   * {@inheritdoc}
   */
  public function verifyValue(ContentEntityInterface $entity, $value, FieldDefinitionInterface $fieldDefinition, array $automatorConfig): bool {
    return is_numeric($value) && $value >= 0;
  }

  /**
   * {@inheritdoc}
   */
  public function storeValues(ContentEntityInterface $entity, array $values, FieldDefinitionInterface $fieldDefinition, array $automatorConfig): bool {
    $entity->set($fieldDefinition->getName(), ['value' => $values[0]]);
    return TRUE;
  }

}
