<?php

namespace Drupal\Tests\dj_mixes_mixcloud\Unit;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests hiding MP3 tracks on DJ mixes that have Mixcloud shows.
 */
#[Group('dj_mixes_mixcloud')]
class TrackVisibilityTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once __DIR__ . '/../../../dj_mixes_mixcloud.module';
  }

  /**
   * Builds to alter, and whether the MP3 tracks stay visible.
   */
  public static function builds(): array {
    $tracks = ['#theme' => 'field', 0 => ['#markup' => 'MP3']];
    $shows = ['#theme' => 'field', 0 => ['#markup' => 'Mixcloud']];
    return [
      'both' => ['dj_mix', ['field_tracks' => $tracks, 'field_mixcloud' => $shows], FALSE],
      'MP3 only' => ['dj_mix', ['field_tracks' => $tracks], TRUE],
      'no show renders' => ['dj_mix', ['field_tracks' => $tracks, 'field_mixcloud' => ['#theme' => 'field']], TRUE],
      'other type' => ['page', ['field_tracks' => $tracks, 'field_mixcloud' => $shows], TRUE],
    ];
  }

  /**
   * MP3 tracks are hidden only next to rendered Mixcloud shows.
   */
  #[DataProvider('builds')]
  public function testTracks(string $bundle, array $build, bool $visible): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn($bundle);
    dj_mixes_mixcloud_node_view_alter($build, $node, $this->createMock(EntityViewDisplayInterface::class));
    $this->assertSame($visible, $build['field_tracks']['#access'] ?? TRUE);
  }

}
