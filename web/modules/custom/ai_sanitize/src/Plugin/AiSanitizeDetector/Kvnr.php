<?php

namespace Drupal\ai_sanitize\Plugin\AiSanitizeDetector;

use Drupal\ai_sanitize\Attribute\AiSanitizeDetector;
use Drupal\ai_sanitize\Detector\DetectorBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Detects German health insurance numbers (Krankenversichertennummer, KVNR).
 *
 * Format: one letter and nine digits, the last being a check digit computed
 * with alternating weights 1,2 over the letter's two-digit alphabet position
 * and the first eight digits.
 */
#[AiSanitizeDetector(
  id: 'kvnr',
  label: new TranslatableMarkup('Health insurance number (KVNR)'),
  placeholder: 'KVNR',
  description: new TranslatableMarkup('German Krankenversichertennummer: one letter and nine digits, validated with its check digit.'),
  weight: 30,
)]
class Kvnr extends DetectorBase {

  /**
   * {@inheritdoc}
   */
  public function detect(string $text): array {
    return $this->matchAll('/(?<![A-Za-z0-9])[A-Za-z](?:[ \-]?\d){9}(?![A-Za-z0-9])/', $text, fn(string $v) => self::isValid(self::compact($v)));
  }

  /**
   * Validates a compact KVNR.
   */
  public static function isValid(string $kvnr): bool {
    if (!preg_match('/^[A-Z]\d{9}$/', $kvnr)) {
      return FALSE;
    }
    $digits = sprintf('%02d', ord($kvnr[0]) - 64) . substr($kvnr, 1, 8);
    $sum = 0;
    for ($i = 0; $i < 10; $i++) {
      $p = (int) $digits[$i] * ($i % 2 === 0 ? 1 : 2);
      $sum += $p > 9 ? $p - 9 : $p;
    }
    return $sum % 10 === (int) $kvnr[9];
  }

}
