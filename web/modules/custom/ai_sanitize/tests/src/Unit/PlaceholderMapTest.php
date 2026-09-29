<?php

namespace Drupal\Tests\ai_sanitize\Unit;

use Drupal\ai_sanitize\PlaceholderMap;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests placeholder numbering.
 */
#[Group('ai_sanitize')]
#[CoversClass(PlaceholderMap::class)]
class PlaceholderMapTest extends UnitTestCase {

  /**
   * The same value keeps its placeholder, regardless of formatting.
   */
  public function testStablePlaceholders(): void {
    $map = new PlaceholderMap();
    $this->assertSame('[IBAN_1]', $map->placeholderFor('IBAN', 'DE89 3704 0044 0532 0130 00'));
    $this->assertSame('[IBAN_2]', $map->placeholderFor('IBAN', 'GB82WEST12345698765432'));
    $this->assertSame('[IBAN_1]', $map->placeholderFor('IBAN', 'de89370400440532013000'));
    $this->assertSame('[EMAIL_1]', $map->placeholderFor('EMAIL', 'a@example.org'));
  }

  /**
   * New placeholders continue after ones already in a conversation.
   */
  public function testReserveExisting(): void {
    $map = new PlaceholderMap();
    $map->reserveExisting('Earlier: [IBAN_1] and [IBAN_3], [EMAIL_2].');
    $this->assertSame('[IBAN_4]', $map->placeholderFor('IBAN', 'DE89 3704 0044 0532 0130 00'));
    $this->assertSame('[EMAIL_3]', $map->placeholderFor('EMAIL', 'a@example.org'));
    $this->assertSame('[KVNR_1]', $map->placeholderFor('KVNR', 'A123456780'));
  }

  /**
   * A custom format is used for creating and reserving.
   */
  public function testCustomFormat(): void {
    $map = new PlaceholderMap('<@type-@n>');
    $map->reserveExisting('<IBAN-2>');
    $this->assertSame('<IBAN-3>', $map->placeholderFor('IBAN', 'x'));
  }

}
