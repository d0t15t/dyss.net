<?php

namespace Drupal\ai_sanitize\Plugin\AiSanitizeDetector;

use Drupal\ai_sanitize\Attribute\AiSanitizeDetector;
use Drupal\ai_sanitize\Detector\DetectedValue;
use Drupal\ai_sanitize\Detector\DetectorBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Detects IBANs, validated with the MOD-97 check digits.
 *
 * Grouped notation ("DE89 3704 0044 0532 0130 00") is supported. The known
 * country length is used to stop exactly at the end of the IBAN, so a word
 * that follows (e.g. "BIC") is never swallowed. Bank-masked IBANs such as
 * "DE87 1005 XXXX XXXX XX59 94" fail the check and are left alone.
 */
#[AiSanitizeDetector(
  id: 'iban',
  label: new TranslatableMarkup('IBAN'),
  placeholder: 'IBAN',
  description: new TranslatableMarkup('International bank account numbers, validated with the MOD-97 check digits.'),
  weight: 0,
)]
class Iban extends DetectorBase {

  /**
   * IBAN lengths per country (SEPA and common others).
   */
  const LENGTHS = [
    'AD' => 24,
    'AT' => 20,
    'BA' => 20,
    'BE' => 16,
    'BG' => 22,
    'CH' => 21,
    'CY' => 28,
    'CZ' => 24,
    'DE' => 22,
    'DK' => 18,
    'EE' => 20,
    'ES' => 24,
    'FI' => 18,
    'FO' => 18,
    'FR' => 27,
    'GB' => 22,
    'GI' => 23,
    'GL' => 18,
    'GR' => 27,
    'HR' => 21,
    'HU' => 28,
    'IE' => 22,
    'IS' => 26,
    'IT' => 27,
    'LI' => 21,
    'LT' => 20,
    'LU' => 20,
    'LV' => 21,
    'MC' => 27,
    'ME' => 22,
    'MK' => 19,
    'MT' => 31,
    'NL' => 18,
    'NO' => 15,
    'PL' => 28,
    'PT' => 25,
    'RO' => 24,
    'RS' => 22,
    'SE' => 24,
    'SI' => 19,
    'SK' => 24,
    'SM' => 27,
    'TR' => 26,
    'UA' => 29,
    'VA' => 22,
  ];

  /**
   * {@inheritdoc}
   */
  public function detect(string $text): array {
    if (!preg_match_all('/(?<![A-Za-z0-9])[A-Za-z]{2}\d{2}/', $text, $starts, PREG_OFFSET_CAPTURE)) {
      return [];
    }
    $found = [];
    $length = strlen($text);
    foreach ($starts[0] as [$prefix, $offset]) {
      $country = strtoupper(substr($prefix, 0, 2));
      $wanted = isset(self::LENGTHS[$country]) ? [self::LENGTHS[$country]] : range(34, 15);
      // Walk forward collecting alphanumerics, allowing single spaces or
      // hyphens between them, and remember where each character ended.
      $chars = '';
      $ends = [];
      $i = $offset;
      while ($i < $length && strlen($chars) < 34) {
        $c = $text[$i];
        if (ctype_alnum($c)) {
          $chars .= strtoupper($c);
          $ends[strlen($chars)] = $i + 1;
          $i++;
        }
        elseif (($c === ' ' || $c === '-') && $i + 1 < $length && ctype_alnum($text[$i + 1])) {
          $i++;
        }
        else {
          break;
        }
      }
      foreach ($wanted as $n) {
        if (strlen($chars) < $n) {
          continue;
        }
        $end = $ends[$n];
        // The IBAN must not continue directly into more alphanumerics.
        if ($end < $length && ctype_alnum($text[$end])) {
          continue;
        }
        if (self::isValid(substr($chars, 0, $n))) {
          $found[] = new DetectedValue($offset, $end - $offset, 'IBAN', substr($text, $offset, $end - $offset));
          break;
        }
      }
    }
    return $found;
  }

  /**
   * Validates a compact IBAN with the MOD-97 algorithm.
   */
  public static function isValid(string $iban): bool {
    if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
      return FALSE;
    }
    $rearranged = substr($iban, 4) . substr($iban, 0, 4);
    $remainder = 0;
    foreach (str_split($rearranged) as $c) {
      $digits = ctype_alpha($c) ? (string) (ord($c) - 55) : $c;
      foreach (str_split($digits) as $d) {
        $remainder = ($remainder * 10 + (int) $d) % 97;
      }
    }
    return $remainder === 1;
  }

}
