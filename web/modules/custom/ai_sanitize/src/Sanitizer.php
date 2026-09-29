<?php

namespace Drupal\ai_sanitize;

use Drupal\ai_sanitize\Detector\DetectedValue;
use Drupal\ai_sanitize\Detector\DetectorManager;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Replaces sensitive values in text with placeholders.
 *
 * Runs the enabled detector plugins plus the configured exact values and
 * custom patterns, drops allowlisted values, resolves overlaps and replaces
 * what is left. Can be used directly, e.g. to sanitize text before storing it
 * in a vector index; AI requests are handled by ProviderRequestSubscriber.
 */
class Sanitizer {

  /**
   * One placeholder map for the whole PHP process (i.e. one conversation).
   */
  protected ?PlaceholderMap $map = NULL;

  /**
   * Instantiated detector plugins, keyed by plugin ID.
   *
   * @var \Drupal\ai_sanitize\Detector\DetectorInterface[]|null
   */
  protected ?array $detectors = NULL;

  public function __construct(
    protected readonly DetectorManager $detectorManager,
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Sanitizes a text.
   *
   * @param string $text
   *   The text.
   * @param \Drupal\ai_sanitize\PlaceholderMap|null $map
   *   Placeholder map to use; defaults to the process-wide map.
   */
  public function sanitize(string $text, ?PlaceholderMap $map = NULL): SanitizeResult {
    if (trim($text) === '') {
      return new SanitizeResult($text);
    }
    $map ??= $this->map();
    $counts = [];
    // Replace from the end so earlier offsets stay valid.
    foreach (array_reverse($this->detect($text)) as $value) {
      $placeholder = $map->placeholderFor($value->type, $value->value);
      $text = substr_replace($text, $placeholder, $value->offset, $value->length);
      $counts[$value->type] = ($counts[$value->type] ?? 0) + 1;
    }
    ksort($counts);
    return new SanitizeResult($text, $counts);
  }

  /**
   * Finds the values that would be replaced, without replacing them.
   *
   * @return \Drupal\ai_sanitize\Detector\DetectedValue[]
   *   Non-overlapping values in text order.
   */
  public function detect(string $text): array {
    $config = $this->configFactory->get('ai_sanitize.settings');
    $found = [];
    foreach ($this->detectors() as $detector) {
      array_push($found, ...$detector->detect($text));
    }
    array_push($found, ...$this->detectExactValues($text, $config->get('always_redact') ?? []));
    array_push($found, ...$this->detectCustomPatterns($text, $config->get('custom_patterns') ?? []));

    $allow = array_flip(array_map([PlaceholderMap::class, 'normalize'], $config->get('allowlist') ?? []));
    $found = array_filter($found, fn(DetectedValue $v) => !isset($allow[PlaceholderMap::normalize($v->value)]));

    // Earliest first; on equal start the longest wins.
    usort($found, fn(DetectedValue $a, DetectedValue $b) => [$a->offset, -$a->length] <=> [$b->offset, -$b->length]);
    $kept = [];
    $end = -1;
    foreach ($found as $value) {
      if ($value->offset >= $end) {
        $kept[] = $value;
        $end = $value->end();
      }
    }
    return $kept;
  }

  /**
   * The process-wide placeholder map.
   */
  public function map(): PlaceholderMap {
    return $this->map ??= new PlaceholderMap($this->configFactory->get('ai_sanitize.settings')->get('placeholder_format') ?: '[@type_@n]');
  }

  /**
   * Starts a new placeholder map, e.g. between unrelated conversations.
   */
  public function resetMap(): void {
    $this->map = NULL;
  }

  /**
   * Instantiates the enabled detectors, in weight order.
   *
   * @return \Drupal\ai_sanitize\Detector\DetectorInterface[]
   *   The detectors.
   */
  protected function detectors(): array {
    if ($this->detectors === NULL) {
      $enabled = $this->configFactory->get('ai_sanitize.settings')->get('enabled_detectors') ?? [];
      $this->detectors = [];
      foreach ($this->detectorManager->getDefinitions() as $id => $definition) {
        if (in_array($id, $enabled, TRUE)) {
          $this->detectors[$id] = $this->detectorManager->createInstance($id);
        }
      }
    }
    return $this->detectors;
  }

  /**
   * Finds configured exact values ("always redact"), case-insensitively.
   *
   * @return \Drupal\ai_sanitize\Detector\DetectedValue[]
   *   The values found.
   */
  protected function detectExactValues(string $text, array $values): array {
    $found = [];
    foreach (array_filter(array_map('trim', $values)) as $value) {
      if (preg_match_all('/' . preg_quote($value, '/') . '/iu', $text, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as [$match, $offset]) {
          $found[] = new DetectedValue($offset, strlen($match), 'REDACTED', $match);
        }
      }
    }
    return $found;
  }

  /**
   * Finds matches of the configured custom patterns.
   *
   * @return \Drupal\ai_sanitize\Detector\DetectedValue[]
   *   The values found.
   */
  protected function detectCustomPatterns(string $text, array $patterns): array {
    $found = [];
    foreach ($patterns as $item) {
      $pattern = $item['pattern'] ?? '';
      $type = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', $item['label'] ?? 'CUSTOM')) ?: 'CUSTOM';
      if ($pattern === '' || @preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE) === FALSE) {
        continue;
      }
      foreach ($m[0] as [$match, $offset]) {
        if ($match !== '') {
          $found[] = new DetectedValue($offset, strlen($match), $type, $match);
        }
      }
    }
    return $found;
  }

}
