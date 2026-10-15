<?php

namespace Drupal\Tests\dj_mixes_mixcloud\Unit;

use Drupal\dj_mixes_mixcloud\MixcloudImporter;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests reading episode and part numbers from Mixcloud and DJ mix titles.
 */
#[Group('dj_mixes_mixcloud')]
class MixcloudImporterTest extends UnitTestCase {

  /**
   * Titles as they appear on Mixcloud and on the site.
   */
  public static function titles(): array {
    return [
      'plain' => ['DYSSEMBLER #1', 1, 0],
      'part word' => ['DYSSEMBLER #2 part 1 featuring DJ Max Mcferren', 2, 1],
      'p and number' => ['Dyssembler Radio #113 p2 / Dimitri So Emotional', 113, 2],
      'p dot' => ['Dyssembler Radio #104 p.2 ~ DJ Your Body', 104, 2],
      'p space' => ['Dyssembler Radio #100 p 1 / DJ Your Body', 100, 1],
      'decimal part' => ['DYSSEMBLER #12.2 / DJ Your Body (dubwise, garage)', 12, 2],
      'typo' => ['Dyssember #97 - Novo Line / Orinoco Flow Takeover', 97, 0],
      'site title' => ['Dyssembler Radio #113 | DJ Your Body, Dimitri So Emotional', 113, 0],
      'number in description' => ['DYSSEMBLER #9 / DJ Your Body (house, uk-garage, 1 hits)', 9, 0],
      'no episode' => ['Still Fadin\' in da Game', NULL, 0],
      'other show' => ['Kynnky Odysseys #20: "Tetrahedral Spheres"', NULL, 0],
    ];
  }

  /**
   * @covers \Drupal\dj_mixes_mixcloud\MixcloudImporter::episode
   * @covers \Drupal\dj_mixes_mixcloud\MixcloudImporter::part
   */
  #[DataProvider('titles')]
  public function testEpisodeAndPart(string $title, ?int $episode, int $part): void {
    $this->assertSame($episode, MixcloudImporter::episode($title));
    $this->assertSame($part, MixcloudImporter::part($title));
  }

}
