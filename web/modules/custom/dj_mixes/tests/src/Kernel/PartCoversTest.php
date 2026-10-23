<?php

namespace Drupal\Tests\dj_mixes\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests choosing between the covers of a multi-part mix's parts.
 */
#[Group('dj_mixes')]
#[RunTestsInSeparateProcesses]
class PartCoversTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'text',
    'filter',
    'node',
    'media',
    'views',
    'datetime',
    'persistent_player',
    'dj_mixes',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'file', 'node', 'media'] as $entity_type) {
      $this->installEntitySchema($entity_type);
    }
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'field', 'filter', 'node', 'media', 'image']);
    // The MP3 media type and DJ mix configuration, as the recipes provide it.
    $installer = $this->container->get('config.installer');
    $root = $this->root . '/../recipes';
    foreach (['mp3_media_type', 'dj_mixes'] as $recipe) {
      $installer->installOptionalConfig(new FileStorage("$root/$recipe/config"));
    }
    $this->setUpCurrentUser([], ['access content', 'view media']);
  }

  /**
   * An image file.
   */
  protected function file(string $name): File {
    $file = File::create(['uri' => "public://$name", 'status' => 1]);
    $file->save();
    return $file;
  }

  /**
   * A DJ mix with an MP3 track per given cover.
   */
  protected function mix(File $cover, array $part_covers): NodeInterface {
    $tracks = [];
    foreach ($part_covers as $index => $part_cover) {
      $track = Media::create([
        'bundle' => 'mp3',
        'name' => 'Part ' . ($index + 1),
        'field_media_mp3_file' => $this->file("part-$index.mp3"),
        'field_cover_art' => $part_cover,
      ]);
      $track->save();
      $tracks[] = $track;
    }
    $node = Node::create([
      'type' => 'dj_mix',
      'title' => 'Dyssembler Radio #108',
      'field_cover_image' => $cover,
      'field_tracks' => $tracks,
    ]);
    $node->save();
    return $node;
  }

  /**
   * The cover part of a mix's page.
   */
  protected function coverBuild(NodeInterface $node): array {
    $view_builder = $this->container->get('entity_type.manager')->getViewBuilder('node');
    return $view_builder->build($view_builder->view($node, 'full'))['field_cover_image'];
  }

  /**
   * Parts with their own covers can be chosen; part 1 shows the mix's cover.
   */
  public function testChoice(): void {
    $cover = $this->file('cover.jpg');
    $other = $this->file('part-2.png');
    $build = $this->coverBuild($this->mix($cover, [$cover, $other]));

    $this->assertSame('dj_mix_covers', $build['#theme']);
    $this->assertSame(['public://cover.jpg', 'public://part-2.png'], array_map(fn($c) => $c['image']['#uri'], $build['#covers']));
    $this->assertSame(['Part 1', 'Part 2'], array_map(fn($c) => (string) $c['label'], $build['#covers']));
    $this->assertContains('dj_mixes/covers', $build['#attached']['library']);
  }

  /**
   * One cover for all parts, or one part, keeps the plain cover image.
   */
  public function testNoChoice(): void {
    $cover = $this->file('cover.jpg');
    $this->assertArrayNotHasKey('#covers', $this->coverBuild($this->mix($cover, [$cover, $cover])));
    $this->assertArrayNotHasKey('#covers', $this->coverBuild($this->mix($cover, [$this->file('part-1.jpg')])));
  }

}
