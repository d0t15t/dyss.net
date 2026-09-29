<?php

namespace Drupal\ai_sanitize\Detector;

/**
 * One sensitive value found in a text.
 *
 * Offsets and lengths are in bytes, as returned by PCRE, so replacements can
 * be applied with substr_replace() on the original string.
 */
final class DetectedValue {

  public function __construct(
    public readonly int $offset,
    public readonly int $length,
    public readonly string $type,
    public readonly string $value,
  ) {}

  /**
   * The byte offset just after the value.
   */
  public function end(): int {
    return $this->offset + $this->length;
  }

}
