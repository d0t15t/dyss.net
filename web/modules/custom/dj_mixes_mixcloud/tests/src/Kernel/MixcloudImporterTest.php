<?php

namespace Drupal\Tests\dj_mixes_mixcloud\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\dj_mixes_mixcloud\MixcloudClient;
use Drupal\dj_mixes_mixcloud\MixcloudImporter;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\OEmbed\Resource;
use Drupal\media\OEmbed\ResourceFetcherInterface;
use Drupal\media\OEmbed\UrlResolverInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests importing Mixcloud shows and linking them to DJ mixes.
 *
 * The Mixcloud API is replaced by canned responses and the oEmbed services by
 * stubs, so nothing is fetched from the network.
 */
#[Group('dj_mixes_mixcloud')]
#[RunTestsInSeparateProcesses]
class MixcloudImporterTest extends KernelTestBase {

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
    'media_library',
    'views',
    'link',
    'datetime',
    'taxonomy',
    'options',
    'persistent_player',
    'dj_mixes',
    'dj_mixes_mixcloud',
  ];

  /**
   * Requests sent to the fake Mixcloud API.
   */
  protected array $requests = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'file', 'node', 'media', 'taxonomy_term'] as $entity_type) {
      $this->installEntitySchema($entity_type);
    }
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'field', 'filter', 'node', 'media', 'image']);

    // The DJ mix and Mixcloud configuration, as the recipes provide it.
    $installer = $this->container->get('config.installer');
    $root = $this->root . '/../recipes';
    foreach (['dj_mixes', 'dj_mixes_mixcloud'] as $recipe) {
      $installer->installOptionalConfig(new FileStorage("$root/$recipe/config"));
    }
    $this->assertNotNull($this->container->get('entity_type.manager')->getStorage('media_type')->load('mixcloud'));
    $this->assertNotNull($this->container->get('entity_type.manager')->getStorage('field_config')->load('node.dj_mix.field_musical_genres'));

    $url_resolver = $this->createMock(UrlResolverInterface::class);
    $url_resolver->method('getResourceUrl')->willReturn('https://www.mixcloud.com/oembed/');
    $resource_fetcher = $this->createMock(ResourceFetcherInterface::class);
    $resource_fetcher->method('fetchResource')->willReturn(Resource::rich('<iframe></iframe>', 600, 60));
    $this->container->set('media.oembed.url_resolver', $url_resolver);
    $this->container->set('media.oembed.resource_fetcher', $resource_fetcher);
  }

  /**
   * An API cloudcast, as in the list and detail responses.
   */
  protected function cloudcast(string $slug, string $name, string $created, array $tags, string $description = '', int $plays = 10): array {
    return [
      'key' => "/BCR_Radio/$slug/",
      'url' => "https://www.mixcloud.com/BCR_Radio/$slug/",
      'name' => $name,
      'created_time' => $created,
      'audio_length' => 3403,
      'user' => ['username' => 'BCR_Radio'],
      'tags' => array_map(fn($tag) => ['name' => $tag], $tags),
      'pictures' => [
        'large' => "https://thumbnailer.example/large/$slug",
        '1024wx1024h' => "https://thumbnailer.example/1024/$slug",
      ],
      'picture_primary_color' => 'BDC5D7',
      'description' => $description,
      'play_count' => $plays,
      'favorite_count' => 4,
      'comment_count' => 1,
      'repost_count' => 2,
      'listener_count' => 3,
    ];
  }

  /**
   * An importer talking to a fake API that answers with these responses.
   */
  protected function importer(array $responses): MixcloudImporter {
    $handler = HandlerStack::create(new MockHandler(array_map(fn($body) => new Response(200, [], json_encode($body)), $responses)));
    $handler->push(function (callable $next) {
      return function ($request, array $options) use ($next) {
        $this->requests[] = (string) $request->getUri();
        return $next($request, $options);
      };
    });
    return new MixcloudImporter(
      new MixcloudClient(new Client(['handler' => $handler])),
      $this->container->get('entity_type.manager'),
      $this->container->get('datetime.time'),
    );
  }

  /**
   * Imports an account's matching shows and links them to their DJ mix.
   */
  public function testImportAndLink(): void {
    $tracklist = "Recorded live.\nJah Carlos - Jah Man\n2. Johnny Clarke - Age is Growing\nEek-a-Mouse – Lick a Shot";
    $part_2 = $this->cloudcast(
      'dyssembler-radio-113-p2',
      'Dyssembler Radio #113 p2 / Dimitri',
      '2018-09-12T10:00:00Z',
      ['Boogie', 'House'],
    );
    $part_1 = $this->cloudcast(
      'dyssembler-radio-113-p1',
      'Dyssembler Radio #113 p1 / DJ Your Body',
      '2018-09-12T09:48:42Z',
      ['House', 'Disco', 'Deep Techno'],
      $tracklist,
      153,
    );
    $other = $this->cloudcast('kynnky-odysseys-20', 'Kynnky Odysseys #20', '2015-02-02T10:00:00Z', ['Synth']);
    $importer = $this->importer([
      ['data' => [$part_2, $part_1, $other], 'paging' => []],
      $part_2,
      $part_1,
    ]);

    $media = $importer->import('BCR_Radio', 'dyssem');
    $this->assertCount(2, $media, 'Only shows matching the filter are imported.');
    $this->assertCount(3, $this->requests, 'One list page and the two matching shows were fetched.');

    $first = $media[$part_1['url']];
    $this->assertSame('Dyssembler Radio #113 p1 / DJ Your Body', $first->getName());
    $this->assertSame($part_1['url'], $first->get('field_media_oembed_mixcloud')->value);
    $this->assertSame('BCR_Radio', $first->get('field_mixcloud_account')->value);
    $this->assertSame('2018-09-12T09:48:42', $first->get('field_mixcloud_uploaded')->value);
    $this->assertSame('3403', (string) $first->get('field_mixcloud_duration')->value);
    $this->assertSame('#bdc5d7', $first->get('field_mixcloud_color')->value);
    $this->assertSame(['House', 'Disco', 'Deep Techno'], array_column($first->get('field_mixcloud_tags')->getValue(), 'value'));
    $this->assertSame('https://thumbnailer.example/1024/dyssembler-radio-113-p1', $first->get('field_mixcloud_picture')->uri, 'The largest picture is used.');
    $this->assertSame('153', (string) $first->get('field_mixcloud_plays')->value);
    $this->assertSame([
      'Jah Carlos – Jah Man',
      'Johnny Clarke – Age is Growing',
      'Eek-a-Mouse – Lick a Shot',
    ], array_column($first->get('field_mixcloud_tracklist')->getValue(), 'value'));

    $mix = Node::create(['type' => 'dj_mix', 'title' => 'Dyssembler Radio #113 | DJ Your Body, Dimitri']);
    $mix->save();
    $unrelated = Node::create(['type' => 'dj_mix', 'title' => 'Dyssembler Radio #112 | IBT']);
    $unrelated->save();

    $linked = $importer->linkToMixes($media);
    $this->assertSame([(string) $mix->id()], array_map('strval', array_keys($linked)));

    $mix = $this->reload($mix);
    $parts = array_map(fn($item) => $item->getName(), $mix->get('field_mixcloud')->referencedEntities());
    $this->assertSame([$part_1['name'], $part_2['name']], $parts, 'Parts are linked in order.');
    $this->assertSame([$part_1['url'], $part_2['url']], array_column($mix->get('field_mixcloud_url')->getValue(), 'uri'));
    $this->assertSame(['House', 'Disco', 'Deep Techno', 'Boogie'], $this->genres($mix), 'Tags of all parts become genres, without duplicates.');
    $this->assertTrue($this->reload($unrelated)->get('field_mixcloud')->isEmpty());

    // Syncing again changes nothing; genres added by hand are kept.
    $this->assertFalse($importer->syncMix($mix));
    $terms = $this->container->get('entity_type.manager')->getStorage('taxonomy_term');
    $extra = $terms->create(['vid' => MixcloudImporter::GENRE_VOCABULARY, 'name' => 'Dub']);
    $extra->save();
    $mix->get('field_musical_genres')->appendItem($extra->id());
    $mix->save();
    $this->assertFalse($importer->syncMix($this->reload($mix)));
    $this->assertContains('Dub', $this->genres($this->reload($mix)));
    $this->assertCount(5, $terms->loadByProperties(['vid' => MixcloudImporter::GENRE_VOCABULARY]), 'Existing genre terms are reused.');

    // A disabled (unpublished) genre is removed, and not added back.
    $disco = $terms->loadByProperties(['vid' => MixcloudImporter::GENRE_VOCABULARY, 'name' => 'Disco']);
    reset($disco)->setUnpublished()->save();
    $this->assertTrue($importer->syncMix($this->reload($mix)));
    $this->assertSame(['House', 'Deep Techno', 'Boogie', 'Dub'], $this->genres($this->reload($mix)));
    $this->assertFalse($importer->syncMix($this->reload($mix)));
  }

  /**
   * Re-importing updates the existing media instead of adding new ones.
   */
  public function testReimport(): void {
    $show = $this->cloudcast('dyssembler-1', 'DYSSEMBLER #1', '2013-10-10T10:00:00Z', ['House'], '', 10);
    $this->importer([['data' => [$show], 'paging' => []], $show])->import('BCR_Radio');
    $show['play_count'] = 25;
    $media = $this->importer([['data' => [$show], 'paging' => []], $show])->import('BCR_Radio');
    $all = $this->container->get('entity_type.manager')->getStorage('media')->loadByProperties(['bundle' => 'mixcloud']);
    $this->assertCount(1, $all);
    $this->assertSame('25', (string) reset($media)->get('field_mixcloud_plays')->value);
  }

  /**
   * A mix's genre names, in order.
   */
  protected function genres(NodeInterface $mix): array {
    return array_map(fn($term) => $term->label(), $mix->get('field_musical_genres')->referencedEntities());
  }

  /**
   * Reloads a node from storage.
   */
  protected function reload(NodeInterface $node): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$node->id()]);
    return $storage->load($node->id());
  }

}
