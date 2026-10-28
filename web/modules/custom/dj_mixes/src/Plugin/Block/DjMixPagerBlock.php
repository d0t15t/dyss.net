<?php

namespace Drupal\dj_mixes\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\dj_mixes\DjMixPager;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Shows the mixes around the current DJ mix, as in the DJ mixes listing.
 *
 * Up to ten cards before and after the current mix, all visible in a grid,
 * with a placeholder for the current mix and arrows to the previous and next
 * mix. The listing's filters and sorting come from the URL
 * (see DjMixPager) and are passed on in every link.
 */
#[Block(
  id: 'dj_mix_pager',
  admin_label: new TranslatableMarkup('DJ mix pager'),
  category: new TranslatableMarkup('DJ Mixes'),
)]
final class DjMixPagerBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Cards shown on each side of the current mix.
   */
  const COUNT = 10;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly DjMixPager $pager,
    private readonly RouteMatchInterface $routeMatch,
    private readonly RequestStack $requestStack,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityRepositoryInterface $entityRepository,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(DjMixPager::class),
      $container->get('current_route_match'),
      $container->get('request_stack'),
      $container->get('entity_type.manager'),
      $container->get('entity.repository'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $node = $this->routeMatch->getParameter('node');
    if (!$node instanceof NodeInterface || $node->bundle() !== 'dj_mix') {
      return [];
    }
    $parameters = $this->pager->parameters($this->requestStack->getCurrentRequest());
    $neighbours = $this->pager->neighbours((int) $node->id(), $parameters, self::COUNT);
    if (!$neighbours) {
      return [];
    }
    $query = $neighbours['parameters'];

    $storage = $this->entityTypeManager->getStorage('node');
    $view_builder = $this->entityTypeManager->getViewBuilder('node');
    $cards = [];
    foreach ([...$neighbours['before'], (int) $node->id(), ...$neighbours['after']] as $nid) {
      // The current mix is marked by a placeholder, not a card.
      if ($nid === (int) $node->id()) {
        $cards[] = ['current' => TRUE, 'content' => NULL];
        continue;
      }
      $mix = $storage->load($nid);
      // In the language of the page.
      $mix = $mix ? $this->entityRepository->getTranslationFromContext($mix) : NULL;
      if ($mix && $mix->access('view')) {
        $cards[] = [
          'current' => FALSE,
          'content' => ['#dj_mix_query' => $query] + $view_builder->view($mix, 'card'),
        ];
      }
    }

    $link = fn(?int $nid) => $nid ? Url::fromRoute('entity.node.canonical', ['node' => $nid], ['query' => $query]) : NULL;
    return [
      '#theme' => 'dj_mix_pager',
      '#cards' => $cards,
      '#previous' => $link(end($neighbours['before']) ?: NULL),
      '#next' => $link($neighbours['after'][0] ?? NULL),
      '#listing' => Url::fromRoute('view.' . DjMixPager::VIEW . '.' . DjMixPager::DISPLAY, [], ['query' => $query]),
      '#position' => $neighbours['position'],
      '#total' => $neighbours['total'],
      '#filtered' => (bool) $query,
      '#attached' => ['library' => ['dj_mixes/pager']],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    $contexts = ['route'];
    foreach ($this->pager->parameterNames() as $name) {
      $contexts[] = 'url.query_args:' . $name;
    }
    return Cache::mergeContexts(parent::getCacheContexts(), $contexts);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    // Any mix added, removed or re-dated changes the order.
    return Cache::mergeTags(parent::getCacheTags(), ['node_list:dj_mix', 'config:views.view.' . DjMixPager::VIEW]);
  }

}
