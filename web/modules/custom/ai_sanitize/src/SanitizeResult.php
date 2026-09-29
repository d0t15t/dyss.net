<?php

namespace Drupal\ai_sanitize;

/**
 * The outcome of sanitizing one text.
 */
final class SanitizeResult {

  /**
   * Constructs a SanitizeResult.
   *
   * @param string $text
   *   The sanitized text.
   * @param array<string, int> $counts
   *   Number of replaced values per placeholder type, e.g. ['IBAN' => 1].
   */
  public function __construct(
    public readonly string $text,
    public readonly array $counts = [],
  ) {}

  /**
   * Whether anything was replaced.
   */
  public function changed(): bool {
    return $this->counts !== [];
  }

}
