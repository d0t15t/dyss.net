<?php

namespace Drupal\ai_sanitize\Plugin\AiSanitizeDetector;

use Drupal\ai_sanitize\Attribute\AiSanitizeDetector;
use Drupal\ai_sanitize\Detector\DetectorBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Detects phone numbers that follow a phone label.
 *
 * Unlabelled digit runs are left alone: they are far more often contract or
 * policy numbers, amounts or dates. Disabled by default, because service
 * hotlines are usually useful context for the AI.
 */
#[AiSanitizeDetector(
  id: 'phone',
  label: new TranslatableMarkup('Phone number'),
  placeholder: 'PHONE',
  description: new TranslatableMarkup('Numbers after labels such as Tel., Telefon, Mobil, Handy, Fax or phone (6-15 digits).'),
  weight: 70,
)]
class Phone extends DetectorBase {

  /**
   * {@inheritdoc}
   */
  public function detect(string $text): array {
    return $this->matchAll(
      '/\b(?:Tel(?:efon)?|Phone|Mobil(?:e|nummer)?|Handy|Telefax|Fax)\b\.?\s*:?\s*(\+?\d[\d \/().\-]{4,}\d)/iu',
      $text,
      function (string $v): bool {
        $n = strlen(preg_replace('/\D/', '', $v));
        return $n >= 6 && $n <= 15;
      },
      1,
    );
  }

}
