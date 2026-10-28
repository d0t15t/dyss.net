<?php

namespace Drupal\dj_mixes\Breadcrumb;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\dj_mixes\DjMixPager;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Puts DJ mixes under the DJ mixes listing: Home › Mixes › the mix.
 *
 * The listing link keeps the filters and sorting the mix was reached with,
 * as the DJ mix pager does. The mix's title is added by dyss_breadcrumb.
 */
class DjMixBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  use StringTranslationTrait;

  public function __construct(
    protected DjMixPager $pager,
    protected RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match, mixed $cacheable_metadata = NULL) {
    $node = $route_match->getParameter('node');
    return $route_match->getRouteName() === 'entity.node.canonical'
      && $node instanceof NodeInterface
      && $node->bundle() === 'dj_mix';
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match) {
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addCacheContexts(['route']);
    $names = $this->pager->parameterNames();
    foreach ($names as $name) {
      $breadcrumb->addCacheContexts(['url.query_args:' . $name]);
    }
    $request = $this->requestStack->getCurrentRequest();
    $query = $request && $names ? $this->pager->parameters($request) : [];
    return $breadcrumb->setLinks([
      Link::createFromRoute($this->t('Home'), '<front>'),
      Link::fromTextAndUrl($this->t('Mixes'), Url::fromRoute('view.' . DjMixPager::VIEW . '.' . DjMixPager::DISPLAY, [], ['query' => $query])),
    ]);
  }

}
