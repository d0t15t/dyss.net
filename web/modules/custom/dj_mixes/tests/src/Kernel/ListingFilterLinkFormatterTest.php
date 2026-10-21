<?php

namespace Drupal\Tests\dj_mixes\Kernel;

use Drupal\Core\Render\Element;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\field\Traits\EntityReferenceFieldCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests linking terms to the DJ mixes listing, filtered by the term.
 */
#[Group('dj_mixes')]
#[RunTestsInSeparateProcesses]
class ListingFilterLinkFormatterTest extends KernelTestBase {

  use EntityReferenceFieldCreationTrait;
  use UserCreationTrait;

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
    'taxonomy',
    'views',
    'dj_mixes',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'node', 'taxonomy_term'] as $entity_type) {
      $this->installEntitySchema($entity_type);
    }
    $this->installConfig(['filter', 'node']);
    $this->setUpCurrentUser([], ['access content']);
    NodeType::create(['type' => 'dj_mix', 'name' => 'DJ mix'])->save();
    Vocabulary::create(['vid' => 'musical_genres', 'name' => 'Musical genres'])->save();
    $this->createEntityReferenceField('node', 'dj_mix', 'field_musical_genres', 'Musical genres', 'taxonomy_term', 'default', ['target_bundles' => ['musical_genres']], -1);
  }

  /**
   * Published terms link to the filtered listing; disabled ones are left out.
   */
  public function testLinks(): void {
    $dub = Term::create(['vid' => 'musical_genres', 'name' => 'Dub']);
    $dub->save();
    $disabled = Term::create(['vid' => 'musical_genres', 'name' => 'Dyssembler', 'status' => 0]);
    $disabled->save();
    $node = Node::create([
      'type' => 'dj_mix',
      'title' => 'Mix',
      'field_musical_genres' => [$dub, $disabled],
    ]);

    $build = $node->get('field_musical_genres')->view([
      'type' => 'dj_mixes_listing_filter_link',
      'settings' => ['parameter' => 'genre'],
    ]);

    $this->assertSame('link', $build[0]['#type']);
    $this->assertSame('Dub', $build[0]['#title']);
    $url = $build[0]['#url'];
    $this->assertSame('view.dj_mixes.page_1', $url->getRouteName());
    $this->assertSame(['genre' => [$dub->id() => $dub->id()]], $url->getOption('query'));
    $this->assertContains('taxonomy_term:' . $dub->id(), $build[0]['#cache']['tags']);

    $this->assertSame([0], Element::children($build));

    // Disabled terms stay out for those who may see them, too.
    $this->setUpCurrentUser([], ['access content', 'administer taxonomy']);
    $build = $node->get('field_musical_genres')->view([
      'type' => 'dj_mixes_listing_filter_link',
      'settings' => ['parameter' => 'genre'],
    ]);
    $this->assertSame([0], Element::children($build));
    $this->assertContains('taxonomy_term:' . $disabled->id(), $build['#cache']['tags']);
  }

}
