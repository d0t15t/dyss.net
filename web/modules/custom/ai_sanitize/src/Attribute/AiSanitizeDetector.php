<?php

namespace Drupal\ai_sanitize\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines an AI Sanitize detector plugin.
 *
 * A detector finds one kind of sensitive value in a text (an IBAN, a tax ID,
 * a PIN …). It only reports where the values are; replacing them with
 * placeholders is done centrally by \Drupal\ai_sanitize\Sanitizer.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class AiSanitizeDetector extends Plugin {

  /**
   * Constructs an AiSanitizeDetector attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   Human-readable name, e.g. "IBAN".
   * @param string $placeholder
   *   Type token used in placeholders, e.g. "IBAN" for "[IBAN_1]".
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   What the detector matches and how it avoids false positives.
   * @param int $weight
   *   Detectors run in weight order; earlier matches win on overlap ties.
   * @param class-string|null $deriver
   *   The deriver class, if any.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly string $placeholder,
    public readonly ?TranslatableMarkup $description = NULL,
    public readonly int $weight = 0,
    public readonly ?string $deriver = NULL,
  ) {}

}
