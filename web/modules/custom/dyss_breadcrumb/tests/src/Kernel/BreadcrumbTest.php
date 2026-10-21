<?php

namespace Drupal\Tests\dyss_breadcrumb\Kernel;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatch;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests ending the breadcrumb with the current page.
 */
#[Group('dyss_breadcrumb')]
#[RunTestsInSeparateProcesses]
class BreadcrumbTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'dyss_breadcrumb',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['filter', 'node']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
  }

  /**
   * Runs the alter hook for a node page with the given trail.
   */
  protected function alter(Node $node, array $links): Breadcrumb {
    $route = $this->container->get('router.route_provider')->getRouteByName('entity.node.canonical');
    $route_match = new RouteMatch('entity.node.canonical', $route, ['node' => $node], ['node' => $node->id()]);
    $request = Request::create('/node/' . $node->id());
    $request->attributes->add(['node' => $node, '_route_object' => $route]);
    $this->container->get('request_stack')->push($request);
    $breadcrumb = (new Breadcrumb())->setLinks($links);
    $context = [];
    $this->container->get('module_handler')->alter('system_breadcrumb', $breadcrumb, $route_match, $context);
    $this->container->get('request_stack')->pop();
    return $breadcrumb;
  }

  /**
   * The page title ends the trail, unlinked, cached per route and node.
   */
  public function testCurrentPage(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Dyssembler Radio #113']);
    $node->save();

    $breadcrumb = $this->alter($node, [Link::createFromRoute('Home', '<front>')]);
    $links = $breadcrumb->getLinks();
    $this->assertCount(2, $links);
    $this->assertSame('Dyssembler Radio #113', (string) $links[1]->getText());
    $this->assertSame('', $links[1]->getUrl()->toString());
    $this->assertContains('route', $breadcrumb->getCacheContexts());
    $this->assertContains('node:' . $node->id(), $breadcrumb->getCacheTags());

    // An empty trail, as on the front page, stays empty.
    $this->assertSame([], $this->alter($node, [])->getLinks());
  }

}
