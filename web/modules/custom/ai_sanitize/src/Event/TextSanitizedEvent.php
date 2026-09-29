<?php

namespace Drupal\ai_sanitize\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Dispatched after values were replaced in an outgoing AI request.
 *
 * Carries only counts per placeholder type, never the values, so UIs can tell
 * the user that something was withheld (e.g. "2 values were withheld from
 * the AI") and logs can record it safely.
 */
class TextSanitizedEvent extends Event {

  const EVENT_NAME = 'ai_sanitize.text_sanitized';

  /**
   * Constructs a TextSanitizedEvent.
   *
   * @param array<string, int> $counts
   *   Replaced values per placeholder type.
   * @param string $operationType
   *   The AI operation type, e.g. "chat".
   * @param string $providerId
   *   The AI provider, e.g. "anthropic".
   * @param string $requestThreadId
   *   The AI module's request thread ID.
   * @param array $tags
   *   The request tags.
   */
  public function __construct(
    public readonly array $counts,
    public readonly string $operationType,
    public readonly string $providerId,
    public readonly string $requestThreadId,
    public readonly array $tags = [],
  ) {}

  /**
   * Total number of replaced values.
   */
  public function total(): int {
    return array_sum($this->counts);
  }

}
