<?php

declare(strict_types=1);

namespace Drupal\receipt_vision_verify\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;

/**
 * Compares vision-extracted values against the node's OCR-extracted fields.
 *
 * Populates field_extraction_confidence (0-100) and field_extraction_discrepancies
 * (JSON dict keyed by field name).
 */
class VisionComparator {

  /**
   * Map of receipt node field name → vision JSON key.
   */
  protected const FIELD_MAP = [
    'field_total_amount' => 'total_amount',
    'field_subtotal_amount' => 'subtotal_amount',
    'field_tax_amount' => 'tax_amount',
    'field_receipt_date' => 'date',
    'field_vendor' => 'vendor',
    'field_currency' => 'currency',
    'field_payment_method' => 'payment_method',
    'field_expense_category' => 'expense_category',
    'field_receipt_number' => 'receipt_number',
  ];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Run comparison for a receipt node. Populates confidence + discrepancies.
   *
   * @return bool
   *   TRUE if comparison ran and populated fields. FALSE if there was no
   *   vision data to compare against.
   */
  public function compare(NodeInterface $node): bool {
    if (!$node->hasField('field_vision_extraction') || $node->get('field_vision_extraction')->isEmpty()) {
      return FALSE;
    }

    $json = $node->get('field_vision_extraction')->value;
    $vision = json_decode((string) $json, TRUE);
    if (!is_array($vision)) {
      return FALSE;
    }

    $weights = $this->configFactory->get('receipt_vision_verify.settings')->get('field_weights') ?? [];
    $discrepancies = [];
    $weightedScore = 0.0;
    $weightedTotal = 0.0;

    foreach (self::FIELD_MAP as $fieldName => $visionKey) {
      $weight = (float) ($weights[$fieldName] ?? 0);
      if ($weight <= 0) {
        continue;
      }

      [$score, $disc] = $this->compareField($node, $fieldName, $vision[$visionKey] ?? NULL);
      $weightedScore += $score * $weight;
      $weightedTotal += $weight;
      if ($disc !== NULL) {
        $discrepancies[$fieldName] = $disc;
      }
    }

    $confidence = $weightedTotal > 0 ? (int) round(($weightedScore / $weightedTotal) * 100) : 0;

    $node->set('field_extraction_confidence', $confidence);
    $node->set('field_extraction_discrepancies', json_encode($discrepancies, JSON_UNESCAPED_UNICODE));
    return TRUE;
  }

  /**
   * Compare one field. Returns [score (0-1), discrepancy record or NULL].
   *
   * @return array{0: float, 1: array|null}
   */
  protected function compareField(NodeInterface $node, string $fieldName, $visionValue): array {
    // OCR side may be empty — in that case it's a discrepancy only if vision has a value.
    if (!$node->hasField($fieldName) || $node->get($fieldName)->isEmpty()) {
      if ($visionValue === NULL || $visionValue === '') {
        return [1.0, NULL];
      }
      return [
        0.0,
        ['ocr' => NULL, 'vision' => $visionValue, 'severity' => 'high'],
      ];
    }

    $ocrValue = $this->extractOcrValue($node, $fieldName);

    // Vision value missing but OCR has one.
    if ($visionValue === NULL || $visionValue === '') {
      return [
        0.0,
        ['ocr' => $ocrValue, 'vision' => NULL, 'severity' => 'high'],
      ];
    }

    // Decide comparison method by field.
    return match ($fieldName) {
      'field_total_amount',
      'field_subtotal_amount',
      'field_tax_amount' => $this->compareNumeric($ocrValue, $visionValue),
      'field_receipt_date' => $this->compareDate($ocrValue, $visionValue),
      'field_vendor',
      'field_receipt_number' => $this->compareString($ocrValue, $visionValue),
      'field_currency',
      'field_payment_method',
      'field_expense_category' => $this->compareTerm($ocrValue, $visionValue),
      default => [1.0, NULL],
    };
  }

  /**
   * Get the OCR-extracted value as a scalar for comparison.
   * Resolves entity references to their label.
   */
  protected function extractOcrValue(NodeInterface $node, string $fieldName): mixed {
    $field = $node->get($fieldName);
    $def = $field->getFieldDefinition();

    if ($def->getType() === 'entity_reference') {
      $target = $field->entity;
      return $target ? (string) $target->label() : NULL;
    }
    if ($def->getType() === 'datetime') {
      $raw = (string) $field->value;
      // Store dates in YYYY-MM-DD form.
      return substr($raw, 0, 10);
    }
    return $field->value;
  }

  protected function compareNumeric(mixed $ocr, mixed $vision): array {
    $ocrNum = (float) $ocr;
    $visionNum = (float) $vision;

    if (abs($ocrNum - $visionNum) < 0.005) {
      return [1.0, NULL];
    }

    $base = max(abs($ocrNum), abs($visionNum), 0.01);
    $diffPct = abs($ocrNum - $visionNum) / $base;

    if ($diffPct <= 0.01) {
      return [
        0.8,
        ['ocr' => (string) $ocr, 'vision' => (string) $vision, 'severity' => 'low'],
      ];
    }
    if ($diffPct <= 0.10) {
      return [
        0.3,
        ['ocr' => (string) $ocr, 'vision' => (string) $vision, 'severity' => 'medium'],
      ];
    }
    return [
      0.0,
      ['ocr' => (string) $ocr, 'vision' => (string) $vision, 'severity' => 'high'],
    ];
  }

  protected function compareDate(mixed $ocr, mixed $vision): array {
    $ocrDate = substr((string) $ocr, 0, 10);
    $visionDate = substr((string) $vision, 0, 10);
    if ($ocrDate === $visionDate) {
      return [1.0, NULL];
    }
    return [
      0.0,
      ['ocr' => $ocrDate, 'vision' => $visionDate, 'severity' => 'high'],
    ];
  }

  protected function compareString(mixed $ocr, mixed $vision): array {
    $a = strtolower(trim((string) $ocr));
    $b = strtolower(trim((string) $vision));
    if ($a === $b) {
      return [1.0, NULL];
    }
    $distance = levenshtein($a, $b);
    if ($distance <= 2) {
      return [
        0.85,
        ['ocr' => (string) $ocr, 'vision' => (string) $vision, 'severity' => 'low'],
      ];
    }
    // Partial match (one string contains the other).
    if (str_contains($a, $b) || str_contains($b, $a)) {
      return [
        0.6,
        ['ocr' => (string) $ocr, 'vision' => (string) $vision, 'severity' => 'low'],
      ];
    }
    return [
      0.0,
      ['ocr' => (string) $ocr, 'vision' => (string) $vision, 'severity' => 'high'],
    ];
  }

  protected function compareTerm(mixed $ocr, mixed $vision): array {
    // Taxonomy terms: compare by label, case-insensitive.
    $a = strtolower(trim((string) $ocr));
    $b = strtolower(trim((string) $vision));
    if ($a === $b) {
      return [1.0, NULL];
    }
    if (levenshtein($a, $b) <= 2) {
      return [
        0.85,
        ['ocr' => (string) $ocr, 'vision' => (string) $vision, 'severity' => 'low'],
      ];
    }
    return [
      0.0,
      ['ocr' => (string) $ocr, 'vision' => (string) $vision, 'severity' => 'medium'],
    ];
  }

}
