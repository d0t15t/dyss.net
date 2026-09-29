<?php

namespace Drupal\ai_sanitize\Plugin\AiSanitizeDetector;

use Drupal\ai_sanitize\Attribute\AiSanitizeDetector;
use Drupal\ai_sanitize\Detector\DetectorBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Detects e-mail addresses.
 */
#[AiSanitizeDetector(
  id: 'email',
  label: new TranslatableMarkup('E-mail address'),
  placeholder: 'EMAIL',
  description: new TranslatableMarkup("E-mail addresses that pass PHP's e-mail validation. Add shared service addresses to the allowlist if the AI should see them."),
  weight: 20,
)]
class Email extends DetectorBase {

  /**
   * {@inheritdoc}
   */
  public function detect(string $text): array {
    return $this->matchAll(
      '/(?<![A-Za-z0-9._%+\-])[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,63}(?![A-Za-z0-9\-])/',
      $text,
      fn(string $value) => filter_var($value, FILTER_VALIDATE_EMAIL) !== FALSE,
    );
  }

}
