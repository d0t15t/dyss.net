<?php

namespace Drupal\dj_mixes\Plugin\views\area;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\views\Attribute\ViewsArea;
use Drupal\views\Plugin\views\area\AreaPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Embeds an arbitrary block by plugin ID as a Views header/footer area.
 *
 * Views core has no built-in "embed any block" area handler. This lets a
 * block (e.g. a Facets facet block) be positioned within a view's own
 * render flow - such as between the exposed filter form and the results -
 * rather than only via a separate theme region.
 */
#[ViewsArea("dj_mixes_block")]
class Block extends AreaPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected BlockManagerInterface $blockManager,
    protected AccountProxyInterface $currentUser,
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
      $container->get('plugin.manager.block'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['block_id'] = ['default' => ''];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);
    $form['block_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Block plugin ID'),
      '#description' => $this->t('E.g. %example', ['%example' => 'facet_block:field_tags']),
      '#default_value' => $this->options['block_id'],
      '#required' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function render($empty = FALSE) {
    if ($empty && empty($this->options['empty'])) {
      return [];
    }
    if (empty($this->options['block_id'])) {
      return [];
    }
    $block = $this->blockManager->createInstance($this->options['block_id'], []);
    if (!$block->access($this->currentUser)) {
      return [];
    }
    return $block->build();
  }

}
