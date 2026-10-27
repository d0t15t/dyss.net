<?php

namespace Drupal\Tests\dj_mixes\Unit;

use Drupal\simple_sitemap\Entity\SimpleSitemapInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests listing each image of a sitemap link once.
 */
#[Group('dj_mixes')]
class SitemapImagesTest extends UnitTestCase {

  /**
   * A mix's cover, also its first part's cover art, is listed once.
   */
  public function testDuplicateImages(): void {
    require_once __DIR__ . '/../../../dj_mixes.module';
    $cover = ['path' => 'https://dyss.net/cover.jpg', 'alt' => NULL, 'title' => 'Dyssembler Radio #108'];
    $part_2 = ['path' => 'https://dyss.net/part-2.png', 'alt' => NULL, 'title' => NULL];
    $links = [
      ['url' => 'https://dyss.net/audio/108', 'images' => [$cover, ['title' => NULL] + $cover, $part_2]],
      ['url' => 'https://dyss.net/dj-mixes', 'images' => NULL],
    ];

    dj_mixes_simple_sitemap_links_alter($links, $this->createMock(SimpleSitemapInterface::class));

    $this->assertSame([$cover, $part_2], $links[0]['images']);
    $this->assertNull($links[1]['images']);
  }

}
