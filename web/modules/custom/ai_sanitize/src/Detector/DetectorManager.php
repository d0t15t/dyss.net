<?php

namespace Drupal\ai_sanitize\Detector;

use Drupal\ai_sanitize\Attribute\AiSanitizeDetector;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Plugin manager for AI Sanitize detectors.
 *
 * Other modules add detectors by placing classes with the
 * #[AiSanitizeDetector] attribute in src/Plugin/AiSanitizeDetector.
 */
class DetectorManager extends DefaultPluginManager {

  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/AiSanitizeDetector', $namespaces, $module_handler, DetectorInterface::class, AiSanitizeDetector::class);
    $this->alterInfo('ai_sanitize_detector_info');
    $this->setCacheBackend($cache_backend, 'ai_sanitize_detector_plugins');
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinitions() {
    $definitions = parent::getDefinitions();
    uasort($definitions, fn($a, $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));
    return $definitions;
  }

}
