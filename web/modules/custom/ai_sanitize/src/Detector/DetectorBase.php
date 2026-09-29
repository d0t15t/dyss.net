<?php

namespace Drupal\ai_sanitize\Detector;

use Drupal\Core\Plugin\PluginBase;

/**
 * Base class for detector plugins.
 */
abstract class DetectorBase extends PluginBase implements DetectorInterface {

  /**
   * Collects regex matches as detected values.
   *
   * @param string $pattern
   *   PCRE pattern. The value to replace is capture group $group.
   * @param string $text
   *   The text to scan.
   * @param callable|null $validate
   *   Optional callback receiving the matched value; return FALSE to skip it.
   * @param int $group
   *   The capture group holding the value.
   *
   * @return \Drupal\ai_sanitize\Detector\DetectedValue[]
   *   The values found.
   */
  protected function matchAll(string $pattern, string $text, ?callable $validate = NULL, int $group = 0): array {
    if ($text === '' || !preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
      return [];
    }
    $found = [];
    foreach ($matches as $match) {
      [$value, $offset] = $match[$group] ?? ['', -1];
      if ($offset < 0 || $value === '' || ($validate && !$validate($value))) {
        continue;
      }
      $found[] = new DetectedValue($offset, strlen($value), $this->pluginDefinition['placeholder'], $value);
    }
    return $found;
  }

  /**
   * Strips everything but ASCII letters and digits, uppercased.
   */
  protected static function compact(string $value): string {
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value));
  }

}
