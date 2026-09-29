<?php

namespace Drupal\ai_sanitize\Plugin\AiSanitizeDetector;

use Drupal\ai_sanitize\Attribute\AiSanitizeDetector;
use Drupal\ai_sanitize\Detector\DetectorBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Detects German tax identification numbers (Steuer-IdNr).
 *
 * Eleven digits, not starting with 0; among the first ten digits exactly one
 * digit occurs two or three times; the last digit is an ISO 7064 MOD 11,10
 * check digit. The structural rules keep ordinary 11-digit numbers (e.g.
 * contract numbers with a leading zero) from matching.
 */
#[AiSanitizeDetector(
  id: 'tax_id',
  label: new TranslatableMarkup('Tax ID (Steuer-IdNr)'),
  placeholder: 'TAX_ID',
  description: new TranslatableMarkup('German Steueridentifikationsnummer (11 digits), validated with its structure rules and check digit.'),
  weight: 50,
)]
class TaxId extends DetectorBase {

  /**
   * {@inheritdoc}
   */
  public function detect(string $text): array {
    return $this->matchAll('/(?<![\d.,])\d{2} ?\d{3} ?\d{3} ?\d{3}(?![\d.,]?\d)/', $text, fn(string $v) => self::isValid(preg_replace('/\D/', '', $v)));
  }

  /**
   * Validates an 11-digit tax ID.
   */
  public static function isValid(string $id): bool {
    if (!preg_match('/^[1-9]\d{10}$/', $id)) {
      return FALSE;
    }
    $counts = array_count_values(str_split(substr($id, 0, 10)));
    $repeated = array_filter($counts, fn($n) => $n > 1);
    if (count($repeated) !== 1 || max($repeated) > 3) {
      return FALSE;
    }
    $product = 10;
    for ($i = 0; $i < 10; $i++) {
      $sum = ((int) $id[$i] + $product) % 10;
      if ($sum === 0) {
        $sum = 10;
      }
      $product = ($sum * 2) % 11;
    }
    $check = 11 - $product;
    return ($check === 10 ? 0 : $check) === (int) $id[10];
  }

}
