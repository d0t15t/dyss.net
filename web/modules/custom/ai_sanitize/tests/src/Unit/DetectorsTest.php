<?php

namespace Drupal\Tests\ai_sanitize\Unit;

use Drupal\ai_sanitize\Detector\DetectorInterface;
use Drupal\ai_sanitize\Plugin\AiSanitizeDetector\CreditCard;
use Drupal\ai_sanitize\Plugin\AiSanitizeDetector\DateOfBirth;
use Drupal\ai_sanitize\Plugin\AiSanitizeDetector\Email;
use Drupal\ai_sanitize\Plugin\AiSanitizeDetector\Iban;
use Drupal\ai_sanitize\Plugin\AiSanitizeDetector\Kvnr;
use Drupal\ai_sanitize\Plugin\AiSanitizeDetector\PensionInsuranceNumber;
use Drupal\ai_sanitize\Plugin\AiSanitizeDetector\Phone;
use Drupal\ai_sanitize\Plugin\AiSanitizeDetector\Secret;
use Drupal\ai_sanitize\Plugin\AiSanitizeDetector\TaxId;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the detector plugins with synthetic values only.
 */
#[Group('ai_sanitize')]
#[CoversClass(Iban::class)]
#[CoversClass(CreditCard::class)]
#[CoversClass(Email::class)]
#[CoversClass(Kvnr::class)]
#[CoversClass(PensionInsuranceNumber::class)]
#[CoversClass(TaxId::class)]
#[CoversClass(DateOfBirth::class)]
#[CoversClass(Secret::class)]
#[CoversClass(Phone::class)]
class DetectorsTest extends UnitTestCase {

  /**
   * Creates a detector plugin instance.
   */
  protected function detector(string $class, string $placeholder): DetectorInterface {
    return new $class([], strtolower($placeholder), ['placeholder' => $placeholder]);
  }

  /**
   * Cases: detector class, placeholder, text, expected matched values.
   */
  public static function cases(): array {
    return [
      'IBAN grouped, BIC after it is not swallowed' => [
        Iban::class,
        'IBAN',
        'IBAN: DE89 3704 0044 0532 0130 00 BIC COBADEFFXXX',
        ['DE89 3704 0044 0532 0130 00'],
      ],
      'IBAN compact, other country' => [
        Iban::class,
        'IBAN',
        'Konto GB82WEST12345698765432.',
        ['GB82WEST12345698765432'],
      ],
      'IBAN wrong check digits' => [Iban::class, 'IBAN', 'DE89 3704 0044 0532 0130 01', []],
      'IBAN masked by the bank' => [Iban::class, 'IBAN', 'IBAN DE87 1005 XXXX XXXX XX59 94', []],
      'Card Visa grouped' => [
        CreditCard::class,
        'CARD',
        'Karte 4111 1111 1111 1111 gültig bis',
        ['4111 1111 1111 1111'],
      ],
      'Card Amex' => [CreditCard::class, 'CARD', 'Amex 378282246310005', ['378282246310005']],
      'Card fails Luhn' => [CreditCard::class, 'CARD', '4111 1111 1111 1112', []],
      'Barcode passes Luhn but is no card' => [CreditCard::class, 'CARD', '*K7020*1234567890123456789*', []],
      'Email' => [Email::class, 'EMAIL', 'Schreiben Sie an kontakt@example.org.', ['kontakt@example.org']],
      'KVNR' => [Kvnr::class, 'KVNR', 'Versichertennr. A123456780', ['A123456780']],
      'KVNR wrong check digit' => [Kvnr::class, 'KVNR', 'A123456781', []],
      'Pension number with spaces' => [
        PensionInsuranceNumber::class,
        'PENSION_NO',
        'Versicherungsnummer 12 010190 M 015',
        ['12 010190 M 015'],
      ],
      'Pension number wrong check digit' => [PensionInsuranceNumber::class, 'PENSION_NO', '12 010190 M 016', []],
      'Tax ID' => [TaxId::class, 'TAX_ID', 'Steuer-IdNr. 86 095 742 719', ['86 095 742 719']],
      'Contract number with leading zero is no tax ID' => [TaxId::class, 'TAX_ID', 'Vertrag 00770472378', []],
      'Birth date after label, lines apart' => [
        DateOfBirth::class,
        'BIRTHDATE',
        "Geburtsdatum\nMax Muster\n01.02.1980",
        ['01.02.1980'],
      ],
      'Birth date with month name' => [
        DateOfBirth::class,
        'BIRTHDATE',
        'Max Muster, geb. 1. Februar 1980',
        ['1. Februar 1980'],
      ],
      'Other dates are kept' => [DateOfBirth::class, 'BIRTHDATE', 'Stand 31.12.2025, Beginn 01.11.2046', []],
      'PIN' => [Secret::class, 'SECRET', 'Ihre persönliche PIN 4711-9823 bitte', ['4711-9823']],
      'Password after colon' => [Secret::class, 'SECRET', 'Passwort: Sommer2026!', ['Sommer2026!']],
      'Word after PIN is no secret' => [Secret::class, 'SECRET', 'PIN mitteilen', []],
      'Labelled phone' => [Phone::class, 'PHONE', 'Tel.: +49 30 1234567', ['+49 30 1234567']],
      'Unlabelled digits are no phone' => [Phone::class, 'PHONE', 'Versicherungsnummer 02-542922-60', []],
    ];
  }

  /**
   * Tests each detector against matching and non-matching text.
   */
  #[DataProvider('cases')]
  public function testDetect(string $class, string $placeholder, string $text, array $expected): void {
    $found = $this->detector($class, $placeholder)->detect($text);
    $this->assertSame($expected, array_map(fn($v) => $v->value, $found));
    foreach ($found as $value) {
      $this->assertSame($value->value, substr($text, $value->offset, $value->length), 'Offsets point at the value.');
      $this->assertSame($placeholder, $value->type);
    }
  }

}
