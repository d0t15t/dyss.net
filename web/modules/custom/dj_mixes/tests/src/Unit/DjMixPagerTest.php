<?php

namespace Drupal\Tests\dj_mixes\Unit;

use Drupal\dj_mixes\DjMixPager;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the window of mixes around the current one.
 */
#[Group('dj_mixes')]
class DjMixPagerTest extends UnitTestCase {

  /**
   * Tests DjMixPager::window().
   */
  public function testWindow(): void {
    $ids = range(1, 30);

    $middle = DjMixPager::window($ids, 15, 10);
    $this->assertSame(range(5, 14), $middle['before'], 'Ten before, nearest last.');
    $this->assertSame(range(16, 25), $middle['after'], 'Ten after, nearest first.');
    $this->assertSame(15, $middle['position']);
    $this->assertSame(30, $middle['total']);

    $first = DjMixPager::window($ids, 1, 10);
    $this->assertSame([], $first['before']);
    $this->assertSame(range(2, 11), $first['after']);

    $near_start = DjMixPager::window($ids, 4, 10);
    $this->assertSame([1, 2, 3], $near_start['before']);

    $last = DjMixPager::window($ids, 30, 10);
    $this->assertSame(range(20, 29), $last['before']);
    $this->assertSame([], $last['after']);

    $this->assertNull(DjMixPager::window($ids, 99, 10), 'Not listed.');
    $this->assertNull(DjMixPager::window([], 1, 10));
  }

  /**
   * Tests DjMixPager::nextPageQuery(), used by infinite scroll.
   */
  public function testNextPageQuery(): void {
    // 106 mixes, 12 per page: pages 0-8.
    $this->assertSame(['page' => 1], DjMixPager::nextPageQuery([], 0, 12, 106));
    $filtered = DjMixPager::nextPageQuery(['name' => 'Radio', 'genre' => '73'], 4, 12, 106);
    $this->assertSame(['name' => 'Radio', 'genre' => '73', 'page' => 5], $filtered, 'The listing parameters are kept.');
    $this->assertSame(['name' => 'Radio', 'page' => 3], DjMixPager::nextPageQuery(['page' => 7, 'name' => 'Radio'], 2, 12, 106), 'A stale page parameter is replaced.');
    $this->assertSame(['page' => 8], DjMixPager::nextPageQuery([], 7, 12, 106), 'The last, partial page is loaded.');
    $this->assertNull(DjMixPager::nextPageQuery([], 8, 12, 106), 'Nothing after the last page.');
    $this->assertNull(DjMixPager::nextPageQuery([], 0, 12, 12), 'A single full page has no next page.');
    $this->assertNull(DjMixPager::nextPageQuery([], 0, 12, 0));
    $this->assertNull(DjMixPager::nextPageQuery([], 0, 0, 106), 'No paging.');
  }

}
