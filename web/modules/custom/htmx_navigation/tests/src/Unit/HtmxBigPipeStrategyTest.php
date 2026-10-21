<?php

namespace Drupal\Tests\htmx_navigation\Unit;

use Drupal\Core\Render\Placeholder\PlaceholderStrategyInterface;
use Drupal\htmx_navigation\Render\HtmxBigPipeStrategy;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests that BigPipe is skipped for htmx requests only.
 */
#[Group('htmx_navigation')]
class HtmxBigPipeStrategyTest extends UnitTestCase {

  /**
   * The strategy for a request, around a BigPipe that handles everything.
   */
  protected function strategy(Request $request): HtmxBigPipeStrategy {
    $big_pipe = $this->createMock(PlaceholderStrategyInterface::class);
    $big_pipe->method('processPlaceholders')->willReturnCallback(fn(array $placeholders) => array_map(fn() => ['#markup' => 'streamed'], $placeholders));
    $stack = new RequestStack();
    $stack->push($request);
    return new HtmxBigPipeStrategy($big_pipe, $stack);
  }

  /**
   * Tests both kinds of requests.
   */
  public function testProcessPlaceholders(): void {
    $placeholders = ['<drupal-render-placeholder>' => ['#markup' => 'x']];

    $page = $this->strategy(Request::create('/node/1'));
    $this->assertCount(1, $page->processPlaceholders($placeholders), 'Page requests use BigPipe.');

    $htmx = Request::create('/node/1');
    $htmx->headers->set('HX-Request', 'true');
    $this->assertSame([], $this->strategy($htmx)->processPlaceholders($placeholders), 'htmx requests do not.');
  }

}
