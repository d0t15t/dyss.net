<?php

namespace Drupal\ai_sanitize\Plugin\AiSanitizeDetector;

use Drupal\ai_sanitize\Attribute\AiSanitizeDetector;
use Drupal\ai_sanitize\Detector\DetectorBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Detects payment card numbers (13-19 digits) that pass the Luhn check.
 */
#[AiSanitizeDetector(
  id: 'credit_card',
  label: new TranslatableMarkup('Payment card number'),
  placeholder: 'CARD',
  description: new TranslatableMarkup('Credit and debit card numbers with 13-19 digits, optionally grouped, validated with the Luhn checksum.'),
  weight: 10,
)]
class CreditCard extends DetectorBase {

  /**
   * Card networks: issuer prefix pattern and valid lengths.
   *
   * Random digit runs pass Luhn one time in ten, and letters and forms are
   * full of them (barcodes, routing codes). Requiring a real network prefix
   * and length keeps those out.
   */
  const NETWORKS = [
    // Visa.
    ['/^4/', [13, 16, 19]],
    // Mastercard.
    ['/^(5[1-5]|222[1-9]|22[3-9]\d|2[3-6]\d\d|27[01]\d|2720)/', [16]],
    // American Express.
    ['/^3[47]/', [15]],
    // Discover.
    ['/^(6011|64[4-9]|65)/', [16, 19]],
    // JCB.
    ['/^35(2[89]|[3-8]\d)/', [16, 17, 18, 19]],
    // Diners Club.
    ['/^(30[0-5]|36|38|39)/', [14, 16, 19]],
  ];

  /**
   * {@inheritdoc}
   */
  public function detect(string $text): array {
    return $this->matchAll('/(?<![\d.,*])\d(?:[ -]?\d){12,18}(?![\d.,*]?\d)/', $text, function (string $value): bool {
      $digits = preg_replace('/\D/', '', $value);
      return self::isCardNumber($digits);
    });
  }

  /**
   * Whether a digit string is a plausible payment card number.
   */
  public static function isCardNumber(string $digits): bool {
    if (count(array_unique(str_split($digits))) < 2) {
      return FALSE;
    }
    foreach (self::NETWORKS as [$prefix, $lengths]) {
      if (preg_match($prefix, $digits) && in_array(strlen($digits), $lengths, TRUE)) {
        return self::luhn($digits);
      }
    }
    return FALSE;
  }

  /**
   * Luhn checksum.
   */
  public static function luhn(string $digits): bool {
    $sum = 0;
    $double = FALSE;
    for ($i = strlen($digits) - 1; $i >= 0; $i--) {
      $d = (int) $digits[$i];
      if ($double) {
        $d *= 2;
        if ($d > 9) {
          $d -= 9;
        }
      }
      $sum += $d;
      $double = !$double;
    }
    return $sum % 10 === 0;
  }

}
