<?php

namespace Drupal\ai_sanitize;

/**
 * Hands out stable, typed placeholders for sensitive values.
 *
 * The same value always gets the same placeholder ("[IBAN_1]"), so the model
 * can still tell that two documents mention the same account. The map never
 * leaves the PHP process: it is not stored, logged or sent anywhere.
 */
final class PlaceholderMap {

  /**
   * Placeholder per normalized value, keyed "TYPE|value".
   *
   * @var array<string, string>
   */
  private array $placeholders = [];

  /**
   * Highest number handed out per type.
   *
   * @var array<string, int>
   */
  private array $counters = [];

  public function __construct(
    private readonly string $format = '[@type_@n]',
  ) {}

  /**
   * Returns the placeholder for a value, creating it on first use.
   */
  public function placeholderFor(string $type, string $value): string {
    $key = $type . '|' . self::normalize($value);
    if (!isset($this->placeholders[$key])) {
      $n = ($this->counters[$type] ?? 0) + 1;
      $this->counters[$type] = $n;
      $this->placeholders[$key] = strtr($this->format, ['@type' => $type, '@n' => $n]);
    }
    return $this->placeholders[$key];
  }

  /**
   * Makes sure new placeholders continue after ones already in a text.
   *
   * Requests in an agent loop carry the earlier, already sanitized messages.
   * Without this, a new value in a later request could get a number that is
   * already used for a different value in the history.
   */
  public function reserveExisting(string $text): void {
    $pattern = '/' . strtr(preg_quote($this->format, '/'), ['@type' => '([A-Z][A-Z0-9_]*?)', '@n' => '(\d+)']) . '/';
    if (@preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
      foreach ($matches as [, $type, $n]) {
        $this->counters[$type] = max($this->counters[$type] ?? 0, (int) $n);
      }
    }
  }

  /**
   * Normalizes a value so formatting differences map to one placeholder.
   */
  public static function normalize(string $value): string {
    return mb_strtolower(preg_replace('/[\s\-.\/]+/u', '', $value));
  }

}
