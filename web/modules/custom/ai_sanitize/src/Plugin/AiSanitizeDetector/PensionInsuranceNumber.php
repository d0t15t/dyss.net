<?php

namespace Drupal\ai_sanitize\Plugin\AiSanitizeDetector;

use Drupal\ai_sanitize\Attribute\AiSanitizeDetector;
use Drupal\ai_sanitize\Detector\DetectorBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Detects German pension/social insurance numbers (Rentenversicherungsnummer).
 *
 * Format: area (2 digits), birth date DDMMYY, initial letter of the birth
 * name, serial (2 digits) and a check digit, e.g. "65 170839 J 00 4". The
 * letter becomes its two-digit alphabet position; the twelve resulting digits
 * are weighted 2,1,2,5,7,1,2,1,2,1,2,1, the cross sums of the products are
 * added up, and the sum modulo 10 is the check digit.
 */
#[AiSanitizeDetector(
  id: 'pension_insurance_number',
  label: new TranslatableMarkup('Pension insurance number (RVNR / SV-Nummer)'),
  placeholder: 'PENSION_NO',
  description: new TranslatableMarkup('German Renten-/Sozialversicherungsnummer (12 characters incl. birth date and a letter), validated with its check digit.'),
  weight: 40,
)]
class PensionInsuranceNumber extends DetectorBase {

  /**
   * {@inheritdoc}
   */
  public function detect(string $text): array {
    return $this->matchAll(
      '/(?<![A-Za-z0-9])\d{2} ?\d{6} ?[A-Za-z] ?\d{2} ?\d(?![A-Za-z0-9])/',
      $text,
      fn(string $v) => self::isValid(self::compact($v)),
    );
  }

  /**
   * Validates a compact RVNR.
   */
  public static function isValid(string $rvnr): bool {
    if (!preg_match('/^(\d{2})(\d{2})(\d{2})(\d{2})([A-Z])(\d{2})(\d)$/', $rvnr, $m)) {
      return FALSE;
    }
    [, $area, $day, $month, $year, $letter, $serial, $check] = $m;
    // Days may carry +50 for later-issued numbers; months must be real.
    $day = (int) $day > 50 ? (int) $day - 50 : (int) $day;
    if ($day < 1 || $day > 31 || (int) $month < 1 || (int) $month > 12) {
      return FALSE;
    }
    $digits = $area . $m[2] . $month . $year . sprintf('%02d', ord($letter) - 64) . $serial;
    $weights = [2, 1, 2, 5, 7, 1, 2, 1, 2, 1, 2, 1];
    $sum = 0;
    foreach ($weights as $i => $w) {
      $p = (int) $digits[$i] * $w;
      $sum += intdiv($p, 10) + $p % 10;
    }
    return $sum % 10 === (int) $check;
  }

}
