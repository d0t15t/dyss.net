<?php

namespace Drupal\ai_sanitize\Detector;

use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * Interface for AI Sanitize detector plugins.
 */
interface DetectorInterface extends PluginInspectionInterface {

  /**
   * Finds the sensitive values of this detector's kind in a text.
   *
   * @param string $text
   *   The text to scan.
   *
   * @return \Drupal\ai_sanitize\Detector\DetectedValue[]
   *   The values found, in any order. Overlaps are resolved by the caller.
   */
  public function detect(string $text): array;

}
