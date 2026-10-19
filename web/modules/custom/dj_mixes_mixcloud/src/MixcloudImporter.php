<?php

namespace Drupal\dj_mixes_mixcloud;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Imports Mixcloud uploads as media and links them to DJ mixes.
 */
final class MixcloudImporter {

  /**
   * Picture sizes in the API, largest first.
   */
  const PICTURE_SIZES = ['1024wx1024h', '768wx768h', '640wx640h', 'extra_large', 'large', 'medium'];

  /**
   * The vocabulary Mixcloud tags are saved to as genres of DJ mixes.
   */
  const GENRE_VOCABULARY = 'musical_genres';

  public function __construct(
    private readonly MixcloudClient $client,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Creates or updates a media item per upload of an account.
   *
   * @param string $username
   *   The Mixcloud username.
   * @param string|null $filter
   *   Only uploads whose name or tags contain this text (case-insensitive).
   * @param callable|null $progress
   *   Called with each media item after saving it.
   *
   * @return \Drupal\media\MediaInterface[]
   *   The saved media, keyed by Mixcloud URL.
   */
  public function import(string $username, ?string $filter = NULL, ?callable $progress = NULL): array {
    $media = [];
    foreach ($this->client->listCloudcasts($username) as $item) {
      $haystack = mb_strtolower($item['name'] . ' ' . implode(' ', array_column($item['tags'] ?? [], 'name')));
      if ($filter !== NULL && !str_contains($haystack, mb_strtolower($filter))) {
        continue;
      }
      $entity = $this->save($this->client->getCloudcast($item['key']));
      $media[$item['url']] = $entity;
      if ($progress) {
        $progress($entity);
      }
    }
    return $media;
  }

  /**
   * Saves one upload's data on its media item, creating it when needed.
   */
  public function save(array $cloudcast): MediaInterface {
    $storage = $this->entityTypeManager->getStorage('media');
    $existing = $storage->loadByProperties(['bundle' => 'mixcloud', 'field_media_oembed_mixcloud' => $cloudcast['url']]);
    $media = reset($existing) ?: $storage->create([
      'bundle' => 'mixcloud',
      'uid' => 1,
      'field_media_oembed_mixcloud' => $cloudcast['url'],
    ]);

    $picture = NULL;
    foreach (self::PICTURE_SIZES as $size) {
      if (!empty($cloudcast['pictures'][$size])) {
        $picture = $cloudcast['pictures'][$size];
        break;
      }
    }
    $color = $cloudcast['picture_primary_color'] ?? '';

    $media->setName(trim($cloudcast['name']));
    $media->set('field_mixcloud_account', $cloudcast['user']['username'] ?? '');
    $media->set('field_mixcloud_uploaded', gmdate(DateTimeItemInterface::DATETIME_STORAGE_FORMAT, strtotime($cloudcast['created_time'])));
    $media->set('field_mixcloud_duration', (int) ($cloudcast['audio_length'] ?? 0));
    $media->set('field_mixcloud_description', trim($cloudcast['description'] ?? ''));
    $media->set('field_mixcloud_color', preg_match('/^[0-9a-f]{6}$/i', $color) ? '#' . strtolower($color) : NULL);
    $media->set('field_mixcloud_tags', array_column($cloudcast['tags'] ?? [], 'name'));
    $media->set('field_mixcloud_picture', $picture ? ['uri' => $picture] : NULL);
    $media->set('field_mixcloud_plays', (int) ($cloudcast['play_count'] ?? 0));
    $media->set('field_mixcloud_favorites', (int) ($cloudcast['favorite_count'] ?? 0));
    $media->set('field_mixcloud_comments', (int) ($cloudcast['comment_count'] ?? 0));
    $media->set('field_mixcloud_reposts', (int) ($cloudcast['repost_count'] ?? 0));
    $media->set('field_mixcloud_listeners', (int) ($cloudcast['listener_count'] ?? 0));
    $media->set('field_mixcloud_synced', $this->time->getRequestTime());
    $media->save();
    return $media;
  }

  /**
   * Links Dyssembler uploads to the DJ mix with the same episode number.
   *
   * A mix's uploads are ordered by part, then by upload date. Links already
   * on a mix are kept.
   *
   * @param \Drupal\media\MediaInterface[] $media
   *   Mixcloud media, e.g. the result of import().
   *
   * @return array<int, string[]>
   *   The names of the linked uploads, keyed by node ID.
   */
  public function linkToMixes(array $media): array {
    $by_episode = [];
    foreach ($media as $item) {
      $episode = self::episode($item->getName());
      if ($episode !== NULL) {
        $by_episode[$episode][] = $item;
      }
    }

    $linked = [];
    $nodes = $this->entityTypeManager->getStorage('node')->loadByProperties(['type' => 'dj_mix']);
    foreach ($nodes as $node) {
      $episode = self::episode($node->label());
      if ($episode === NULL || empty($by_episode[$episode])) {
        continue;
      }
      $items = $this->mergeLinks($node, $by_episode[$episode]);
      $this->syncMix($node);
      $linked[$node->id()] = array_map(fn(MediaInterface $item) => $item->getName(), $items);
    }
    return $linked;
  }

  /**
   * Copies data from a DJ mix's Mixcloud shows onto the mix.
   *
   * The mix gets the shows' URLs and their tags as musical genres (terms are
   * created as needed). Genres already on the mix are kept.
   *
   * @return bool
   *   Whether the mix changed and was saved.
   */
  public function syncMix(NodeInterface $node): bool {
    $urls = [];
    $genres = array_column($node->get('field_musical_genres')->getValue(), 'target_id');
    foreach ($node->get('field_mixcloud')->referencedEntities() as $item) {
      $urls[] = ['uri' => $item->get('field_media_oembed_mixcloud')->value];
      foreach ($item->get('field_mixcloud_tags') as $tag) {
        $genres[] = $this->genre($tag->value)->id();
      }
    }
    $genres = array_values(array_unique(array_map('intval', $genres)));

    $changed = FALSE;
    if (array_column($node->get('field_mixcloud_url')->getValue(), 'uri') !== array_column($urls, 'uri')) {
      $node->set('field_mixcloud_url', $urls);
      $changed = TRUE;
    }
    if (array_map('intval', array_column($node->get('field_musical_genres')->getValue(), 'target_id')) !== $genres) {
      $node->set('field_musical_genres', $genres);
      $changed = TRUE;
    }
    if ($changed) {
      $node->save();
    }
    return $changed;
  }

  /**
   * Syncs every DJ mix that has Mixcloud shows (see syncMix()).
   *
   * @return int
   *   The number of mixes that changed.
   */
  public function syncMixes(): int {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'dj_mix')->exists('field_mixcloud')->execute();
    $changed = 0;
    foreach ($storage->loadMultiple($ids) as $node) {
      $changed += (int) $this->syncMix($node);
    }
    return $changed;
  }

  /**
   * The genre term with a name, created if needed.
   */
  private function genre(string $name): TermInterface {
    $name = trim($name);
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    foreach ($storage->loadByProperties(['vid' => self::GENRE_VOCABULARY, 'name' => $name]) as $term) {
      // The database comparison ignores case; keep the first exact match,
      // else any.
      if ($term->getName() === $name) {
        return $term;
      }
      $match ??= $term;
    }
    if (isset($match)) {
      return $match;
    }
    $term = $storage->create(['vid' => self::GENRE_VOCABULARY, 'name' => $name]);
    $term->save();
    return $term;
  }

  /**
   * Adds uploads to a mix's Mixcloud field in part order and saves it.
   *
   * @return \Drupal\media\MediaInterface[]
   *   All uploads now linked to the mix.
   */
  private function mergeLinks(NodeInterface $node, array $new): array {
    $items = [];
    foreach ([...$node->get('field_mixcloud')->referencedEntities(), ...$new] as $item) {
      $items[$item->id()] = $item;
    }
    $order = fn(MediaInterface $item) => [
      self::part($item->getName()),
      $item->get('field_mixcloud_uploaded')->value,
    ];
    uasort($items, fn(MediaInterface $a, MediaInterface $b) => $order($a) <=> $order($b));
    $ids = array_keys($items);
    if (array_column($node->get('field_mixcloud')->getValue(), 'target_id') != $ids) {
      $node->set('field_mixcloud', $ids);
      $node->save();
    }
    return array_values($items);
  }

  /**
   * The Dyssembler episode number in a title, e.g. 113 for "#113 p2".
   *
   * Also matches "Dyssember", a typo on one upload.
   */
  public static function episode(string $title): ?int {
    return preg_match('/dyssem.*?#\s*(\d+)/i', $title, $match) ? (int) $match[1] : NULL;
  }

  /**
   * The part number in a title ("p2", "part 2", "p.2", "#12.2"), else 0.
   */
  public static function part(string $title): int {
    if (preg_match('/\b(?:p|part)\s*\.?\s*(\d+)\b/i', $title, $match) || preg_match('/#\s*\d+\.(\d+)/', $title, $match)) {
      return (int) $match[1];
    }
    return 0;
  }

}
