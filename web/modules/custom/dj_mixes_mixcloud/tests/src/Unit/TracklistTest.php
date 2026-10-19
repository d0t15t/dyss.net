<?php

namespace Drupal\Tests\dj_mixes_mixcloud\Unit;

use Drupal\dj_mixes_mixcloud\Tracklist;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests reading tracklists from Mixcloud show descriptions.
 */
#[Group('dj_mixes_mixcloud')]
class TracklistTest extends UnitTestCase {

  /**
   * A description with links, a date and a numbered, timed tracklist.
   */
  public function testParse(): void {
    $description = <<<TEXT
      DYSSEMBLER #32 part 1 with DJ Your Body
      28. July, 2014
      http://berlincommunityradio.com/DYSSEMBLER
      Jah Carlos - Jah Man
      2. Johnny Clarke - Age is Growing
      [00:12:30] Junior Peterkin & The Idrins – Babylon Take I Down
      Mashed up dubby step glitch -core  : - }
      Eek-a-Mouse — Lick a Shot
      TEXT;
    $this->assertSame([
      'Jah Carlos – Jah Man',
      'Johnny Clarke – Age is Growing',
      'Junior Peterkin & The Idrins – Babylon Take I Down',
      'Eek-a-Mouse – Lick a Shot',
    ], Tracklist::parse($description));
  }

  /**
   * One or two dashed lines are prose, not a tracklist.
   */
  public function testTooFewTracks(): void {
    $this->assertSame([], Tracklist::parse("Moody electro - and some snappy house.\nRecorded in Kiev - 2017"));
    $this->assertSame([], Tracklist::parse(''));
  }

  /**
   * The last line of a description cut off by the API is dropped.
   */
  public function testCutOffDescription(): void {
    $lines = array_map(fn($i) => "Artist $i - Title $i", range(1, 60));
    $description = mb_substr(implode("\n", $lines), 0, Tracklist::API_MAX_LENGTH);
    $tracks = Tracklist::parse($description);
    $this->assertSame('Artist 1 – Title 1', $tracks[0]);
    $last = end($tracks);
    $this->assertMatchesRegularExpression('/^Artist (\d+) – Title \1$/', $last, 'The last track is complete.');
  }

}
