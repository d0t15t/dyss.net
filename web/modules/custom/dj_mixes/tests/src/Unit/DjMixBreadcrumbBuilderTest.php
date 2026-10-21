<?php

namespace Drupal\Tests\dj_mixes\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\dj_mixes\Breadcrumb\DjMixBreadcrumbBuilder;
use Drupal\dj_mixes\DjMixPager;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests putting DJ mixes under the DJ mixes listing in the breadcrumb.
 */
#[Group('dj_mixes')]
class DjMixBreadcrumbBuilderTest extends UnitTestCase {

  /**
   * A builder for a request to this URL.
   */
  protected function builder(string $uri): DjMixBreadcrumbBuilder {
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    $cache_contexts = $this->createMock(CacheContextsManager::class);
    $cache_contexts->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $cache_contexts);
    \Drupal::setContainer($container);

    $request_stack = new RequestStack();
    $request_stack->push(Request::create($uri));
    $pager = $this->createMock(DjMixPager::class);
    $names = ['name', 'genre', 'tags'];
    $pager->method('parameterNames')->willReturn($names);
    $pager->method('parameters')->willReturnCallback(
      fn(Request $request) => array_intersect_key($request->query->all(), array_flip($names)),
    );
    return new DjMixBreadcrumbBuilder($pager, $request_stack);
  }

  /**
   * A route match for a node's page.
   */
  protected function nodeRoute(string $bundle, string $route = 'entity.node.canonical'): RouteMatchInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn($bundle);
    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getRouteName')->willReturn($route);
    $route_match->method('getParameter')->with('node')->willReturn($node);
    return $route_match;
  }

  /**
   * Only DJ mix pages get this breadcrumb.
   */
  public function testApplies(): void {
    $builder = $this->builder('/node/1');
    $this->assertTrue($builder->applies($this->nodeRoute('dj_mix')));
    $this->assertFalse($builder->applies($this->nodeRoute('page')));
    $this->assertFalse($builder->applies($this->nodeRoute('dj_mix', 'entity.node.edit_form')));
  }

  /**
   * Home › DJ Mixes, with the listing's filters kept and nothing else.
   */
  public function testBuild(): void {
    $breadcrumb = $this->builder('/node/1?genre[73]=73&utm_source=x')->build($this->nodeRoute('dj_mix'));
    $links = $breadcrumb->getLinks();
    $this->assertCount(2, $links);
    $this->assertSame('Home', (string) $links[0]->getText());
    $this->assertSame('DJ Mixes', (string) $links[1]->getText());
    $this->assertSame('view.dj_mixes.page_1', $links[1]->getUrl()->getRouteName());
    $this->assertSame(['genre' => ['73' => '73']], $links[1]->getUrl()->getOption('query'));
    $this->assertEqualsCanonicalizing(
      ['route', 'url.query_args:name', 'url.query_args:genre', 'url.query_args:tags'],
      $breadcrumb->getCacheContexts(),
    );

    $plain = $this->builder('/node/1')->build($this->nodeRoute('dj_mix'));
    $this->assertSame([], $plain->getLinks()[1]->getUrl()->getOption('query'));
  }

}
