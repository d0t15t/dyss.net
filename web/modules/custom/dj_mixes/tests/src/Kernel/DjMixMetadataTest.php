<?php

namespace Drupal\Tests\dj_mixes\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\field\Entity\FieldConfig;
use Drupal\dj_mixes\DjMixMetadata;
use Drupal\file\Entity\File;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\field\Traits\EntityReferenceFieldCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests describing DJ mixes: description, JSON-LD and llms.txt.
 */
#[Group('dj_mixes')]
#[RunTestsInSeparateProcesses]
class DjMixMetadataTest extends KernelTestBase {

  use EntityReferenceFieldCreationTrait;
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
    'taxonomy',
    'language',
    'locale',
    'persistent_player',
    'dj_mixes',
  ];

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
    $installer = $this->container->get('config.installer');
    $root = $this->root . '/../recipes';
    foreach (['mp3_media_type', 'dj_mixes'] as $recipe) {
      $installer->installOptionalConfig(new FileStorage("$root/$recipe/config"));
    }
    foreach (['tags' => 'field_tags', 'musical_genres' => 'field_musical_genres'] as $vocabulary => $field) {
      Vocabulary::create(['vid' => $vocabulary, 'name' => $vocabulary])->save();
      $this->createEntityReferenceField('node', 'dj_mix', $field, $field, 'taxonomy_term', 'default', ['target_bundles' => [$vocabulary]], -1);
    }
    $this->setUpCurrentUser([], ['access content', 'view media']);
  }

  /**
   * A term.
   */
  protected function term(string $vocabulary, string $name, bool $published = TRUE): Term {
    $term = Term::create(['vid' => $vocabulary, 'name' => $name, 'status' => $published]);
    $term->save();
    return $term;
  }

  /**
   * A DJ mix with one MP3 part.
   */
  protected function mix(string $title, ?string $date, array $tags = [], array $genres = []): NodeInterface {
    $track = Media::create([
      'bundle' => 'mp3',
      'name' => "$title (part 1)",
      'field_media_mp3_file' => File::create(['uri' => 'public://mix.mp3', 'status' => 1]),
      'field_duration' => '1:03:38',
    ]);
    $track->get('field_media_mp3_file')->entity->save();
    $track->save();
    $mix = Node::create([
      'type' => 'dj_mix',
      'title' => $title,
      'field_mix_date' => $date,
      'field_cover_image' => File::create(['uri' => 'public://cover.jpg', 'status' => 1]),
      'field_tracks' => [$track],
      'field_tags' => $tags,
      'field_musical_genres' => $genres,
    ]);
    $mix->get('field_cover_image')->entity->save();
    $mix->save();
    return $mix;
  }

  /**
   * A Dyssembler Radio episode is a RadioEpisode, broadcast on BCR.
   */
  public function testEpisode(): void {
    $bcr = $this->term('tags', DjMixMetadata::STATION);
    $genres = [
      $this->term('musical_genres', 'Electronics'),
      $this->term('musical_genres', 'Dark wave'),
      // Disabled genres are left out.
      $this->term('musical_genres', 'Dyssembler', FALSE),
    ];
    $mix = $this->mix('Dyssembler Radio #115 | Rugilė, RUDEBOY BADMAN & Dionysus', '2018-08-28', [$bcr], $genres);
    $metadata = $this->container->get(DjMixMetadata::class);

    $description = 'Dyssembler Radio #115 with Rugilė, RUDEBOY BADMAN & Dionysus, '
      . 'broadcast on Berlin Community Radio on 28 August 2018. Genres: Electronics, Dark wave.';
    $this->assertSame($description, $metadata->description($mix));
    $this->assertSame($description, $this->container->get('token')->replacePlain('[node:dj-mix-description]', ['node' => $mix]));

    $data = $metadata->structuredData($mix);
    $this->assertSame('RadioEpisode', $data['@type']);
    $this->assertSame(115, $data['episodeNumber']);
    $this->assertSame('2018-08-28', $data['datePublished']);
    $this->assertSame(['Electronics', 'Dark wave'], $data['genre']);
    $this->assertSame(['Rugilė', 'RUDEBOY BADMAN', 'Dionysus'], array_column($data['contributor'], 'name'));
    $this->assertSame(DjMixMetadata::SERIES, $data['partOfSeries']['name']);
    $this->assertSame(DjMixMetadata::STATION, $data['publication']['publishedOn']['name']);
    $this->assertSame('2018-08-28', $data['publication']['startDate']);
    $this->assertSame('PT1H3M38S', $data['audio'][0]['duration']);
    $this->assertSame('audio/mpeg', $data['audio'][0]['encodingFormat']);
    $this->assertStringEndsWith('/mix.mp3', $data['audio'][0]['contentUrl']);
    $this->assertStringEndsWith('/cover.jpg', $data['image']);
  }

  /**
   * Other mixes are MusicRecordings by their artists; dates may be unknown.
   */
  public function testOtherMix(): void {
    $mix = $this->mix('Jahwort i | DJ Your Body & DJ Sweat', NULL);
    $metadata = $this->container->get(DjMixMetadata::class);

    $this->assertSame('Jahwort i, a DJ mix by DJ Your Body & DJ Sweat.', $metadata->description($mix));
    $data = $metadata->structuredData($mix);
    $this->assertSame('MusicRecording', $data['@type']);
    $this->assertArrayNotHasKey('datePublished', $data);
    $this->assertArrayNotHasKey('episodeNumber', $data);
    $this->assertSame(['DJ Your Body', 'DJ Sweat'], array_column($data['byArtist'], 'name'));
    $this->assertStringEndsWith('#ibt', $data['byArtist'][0]['@id']);
    $this->assertArrayNotHasKey('@id', $data['byArtist'][1]);
  }

  /**
   * A German translation is described in German, with the same identities.
   */
  public function testGerman(): void {
    $this->installSchema('locale', ['locales_source', 'locales_target', 'locales_location', 'locale_file']);
    $this->installConfig(['language', 'locale']);
    ConfigurableLanguage::createFromLangcode('de')->save();
    $storage = $this->container->get('locale.storage');
    foreach ([
      ['j F Y', 'DJ mix date', 'j. F Y'],
      ['October', 'Long month name', 'Oktober'],
      ['@show with @artists', '', '@show mit @artists'],
      ['@text, broadcast on @station on @date', '', '@text, gesendet auf @station am @date'],
    ] as [$source, $context, $translation]) {
      $string = $storage->createString(['source' => $source, 'context' => $context])->save();
      $storage->createTranslation(['lid' => $string->lid, 'language' => 'de', 'translation' => $translation])->save();
    }
    // As on the site: the tags are the same in all languages.
    FieldConfig::loadByName('node', 'dj_mix', 'field_tags')->setTranslatable(FALSE)->save();
    $mix = $this->mix('Dyssembler Radio #2 | DJ Your Body & DJ Sweat', '2013-10-24', [$this->term('tags', DjMixMetadata::STATION)]);
    $mix->addTranslation('de', ['title' => $mix->label()])->save();
    $metadata = $this->container->get(DjMixMetadata::class);

    $german = $mix->getTranslation('de');
    // Names stay as they are, not escaped.
    $this->assertSame('Dyssembler Radio #2 mit DJ Your Body & DJ Sweat, gesendet auf Berlin Community Radio am 24. Oktober 2013.', $metadata->description($german));
    $this->assertStringStartsWith('Dyssembler Radio #2 with DJ Your Body & DJ Sweat, broadcast on', $metadata->description($mix));
    $data = $metadata->structuredData($german);
    $this->assertSame('de', $data['inLanguage']);
    $this->assertSame($metadata->structuredData($mix)['publisher']['@id'], $data['publisher']['@id']);
  }

  /**
   * The llms.txt section lists the published mixes, newest first.
   */
  public function testLlmsTxt(): void {
    $this->mix('Dyssembler Radio #2 | DJ Your Body', '2013-10-24');
    $this->mix('Dyssembler Radio #3 | DJ Your Body', '2013-11-04');
    $this->mix('Hidden | DJ Your Body', '2013-12-01')->setUnpublished()->save();

    $lines = explode("\n", $this->container->get(DjMixMetadata::class)->llmsTxt());
    $this->assertSame('## DJ mixes', $lines[0]);
    $this->assertCount(4, $lines);
    $this->assertStringStartsWith('- [Dyssembler Radio #3 | DJ Your Body](http', $lines[2]);
    $this->assertStringContainsString('#2 with DJ Your Body, 24 October 2013.', $lines[3]);
  }

}
