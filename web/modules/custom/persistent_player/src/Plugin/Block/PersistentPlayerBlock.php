<?php

namespace Drupal\persistent_player\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the persistent media player.
 *
 * Place this block once, in a region your theme never replaces during
 * same-site AJAX navigation (i.e. outside the element matched by
 * persistent_player.settings:content_selector).
 */
#[Block(
  id: "persistent_player",
  admin_label: new TranslatableMarkup("Persistent media player"),
  category: new TranslatableMarkup("Media"),
)]
class PersistentPlayerBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the block plugin.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['label_display' => '0'];
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $settings = $this->configFactory->get('persistent_player.settings');

    return [
      '#theme' => 'persistent_player',
      '#attached' => [
        'library' => [
          'persistent_player/persistent-player',
          'persistent_player/pjax-nav',
        ],
        'drupalSettings' => [
          'persistentPlayer' => [
            'contentSelector' => $settings->get('content_selector'),
            'enabledPjaxNav' => (bool) $settings->get('enabled_pjax_nav'),
            'excludedPathPatterns' => $settings->get('excluded_path_patterns') ?? [],
          ],
        ],
      ],
      '#cache' => [
        'tags' => $settings->getCacheTags(),
      ],
    ];
  }

}
