<?php

namespace Drupal\Tests\dj_mixes\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\dj_mixes\DjMixMetadata;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the parts of DJ mix metadata read from titles and durations.
 */
#[Group('dj_mixes')]
class DjMixMetadataTest extends UnitTestCase {

  /**
   * The metadata service, without storage.
   */
  protected function metadata(): DjMixMetadata {
    return new DjMixMetadata(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(FileUrlGeneratorInterface::class),
    );
  }

  /**
   * A mix with this title.
   */
  protected function mix(string $title): NodeInterface {
    $mix = $this->createMock(NodeInterface::class);
    $mix->method('label')->willReturn($title);
    return $mix;
  }

  /**
   * Titles, and the show, episode and artists read from them.
   */
  public static function titles(): array {
    $episode = 'Dyssembler Radio #115';
    return [
      'episode' => [
        "$episode | Rugilė, RUDEBOY BADMAN & Dionysus",
        $episode,
        115,
        ['Rugilė', 'RUDEBOY BADMAN', 'Dionysus'],
      ],
      'note in brackets' => [
        'Dyssembler Radio #97 | Novoline (Orinoco Flow takeover)',
        'Dyssembler Radio #97',
        97,
        ['Novoline'],
      ],
      'slash' => ['Dyssembler Radio #99 | Florian Kupfer / IBT', 'Dyssembler Radio #99', 99, ['Florian Kupfer', 'IBT']],
      'name with slashes' => [
        'Dyssembler Radio #113 | L. Zylberberg & dane//close',
        'Dyssembler Radio #113',
        113,
        ['L. Zylberberg', 'dane//close'],
      ],
      'not an episode' => ["Still Fadin' in da Game | DJ Your Body", "Still Fadin' in da Game", NULL, ['DJ Your Body']],
      'other Dyssembler mix' => ['Dyssembler Mix | DJ Rueckert', 'Dyssembler Mix', NULL, ['DJ Rueckert']],
      'no artists' => ['Dyssembler Radio #1', 'Dyssembler Radio #1', 1, []],
    ];
  }

  /**
   * The show, episode number and artists come from the title.
   */
  #[DataProvider('titles')]
  public function testTitles(string $title, string $show, ?int $episode, array $artists): void {
    $metadata = $this->metadata();
    $mix = $this->mix($title);
    $this->assertSame($show, $metadata->titleParts($mix)[0]);
    $this->assertSame($episode, $metadata->episodeNumber($mix));
    $this->assertSame($artists, $metadata->artists($mix));
  }

  /**
   * Durations, as ISO 8601 and from "h:mm:ss".
   */
  public function testDurations(): void {
    $this->assertSame('PT1H3M38S', DjMixMetadata::isoDuration(3818));
    $this->assertSame('PT52M31S', DjMixMetadata::isoDuration(3151));
    $this->assertSame('PT2H', DjMixMetadata::isoDuration(7200));
    $this->assertSame('PT0S', DjMixMetadata::isoDuration(0));
    $this->assertSame(3818, DjMixMetadata::seconds('1:03:38'));
    $this->assertSame(3151, DjMixMetadata::seconds('52:31'));
    $this->assertSame(0, DjMixMetadata::seconds('about an hour'));
  }

}
