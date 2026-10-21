<?php

namespace Drupal\persistent_player\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides the persistent media player.
 *
 * Place this block once, in a region same-site navigation never replaces
 * (outside htmx_navigation.settings:content_selector), so it keeps playing.
 */
#[Block(
  id: "persistent_player",
  admin_label: new TranslatableMarkup("Persistent media player"),
  category: new TranslatableMarkup("Media"),
)]
class PersistentPlayerBlock extends BlockBase {

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
    return [
      '#theme' => 'persistent_player',
      '#attached' => [
        'library' => ['persistent_player/persistent-player'],
      ],
    ];
  }

}
