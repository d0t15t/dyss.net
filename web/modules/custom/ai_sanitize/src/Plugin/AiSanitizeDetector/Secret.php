<?php

namespace Drupal\ai_sanitize\Plugin\AiSanitizeDetector;

use Drupal\ai_sanitize\Attribute\AiSanitizeDetector;
use Drupal\ai_sanitize\Detector\DetectorBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Detects PINs, TANs, passwords and access codes following their label.
 *
 * The value must contain a digit, so ordinary words after a label ("PIN
 * mitteilen") are not treated as secrets.
 */
#[AiSanitizeDetector(
  id: 'secret',
  label: new TranslatableMarkup('PIN, password or access code'),
  placeholder: 'SECRET',
  description: new TranslatableMarkup('Values after labels such as PIN, TAN, Passwort, Kennwort, password or Zugangscode that contain at least one digit.'),
  weight: 5,
)]
class Secret extends DetectorBase {

  /**
   * {@inheritdoc}
   */
  public function detect(string $text): array {
    $label = '\b(?:PIN|TAN|Passwort|Kennwort|Password|Passcode|Zugangscode|Freischaltcode|Aktivierungscode|Zugangsdaten)\b';
    return $this->matchAll(
      '/' . $label . '(?:\s*(?:ist|lautet|is|:|=|-)\s*|\s+)([A-Za-z0-9!$%&*#@+._\-]{4,64})/iu',
      $text,
      fn(string $v) => (bool) preg_match('/\d/', $v),
      1,
    );
  }

}
