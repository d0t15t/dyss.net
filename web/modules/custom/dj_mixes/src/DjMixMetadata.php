<?php

namespace Drupal\dj_mixes;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Url;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Describes DJ mixes for search engines, link previews and language models.
 *
 * - description(): one line about a mix, for its meta description and share
 *   cards (the [node:dj-mix-description] token).
 * - structuredData(): the mix as schema.org JSON-LD - a RadioEpisode of
 *   Dyssembler Radio, or else a MusicRecording - with its artists, genres,
 *   cover and its parts as audio.
 * - llmsTxt(): the list of mixes for /llms.txt.
 */
class DjMixMetadata {

  /**
   * The radio show; mixes titled "Dyssembler Radio #N | ..." are episodes.
   */
  const SERIES = 'Dyssembler Radio';

  /**
   * The tag of mixes broadcast on Berlin Community Radio.
   */
  const STATION = 'Berlin Community Radio';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * One line about a mix: show, artists, station, date and genres.
   */
  public function description(NodeInterface $mix): string {
    [$show, $artists] = $this->titleParts($mix);
    $date = $this->date($mix, 'j F Y');
    $on_air = $this->onStation($mix);

    if ($this->episodeNumber($mix) !== NULL) {
      $text = $artists ? "$show with $artists" : $show;
      if ($on_air) {
        $text .= ', broadcast on ' . self::STATION . ($date ? " on $date" : '');
      }
      elseif ($date) {
        $text .= ", $date";
      }
    }
    else {
      $text = $artists ? "$show, a DJ mix by $artists" : "$show, a DJ mix";
      $text .= $date ? ", $date" : '';
    }
    $text .= '.';
    if ($genres = $this->genres($mix)) {
      $text .= ' Genres: ' . implode(', ', array_slice($genres, 0, 6)) . '.';
    }
    return $text;
  }

  /**
   * The mix as schema.org JSON-LD.
   */
  public function structuredData(NodeInterface $mix): array {
    $site = Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString();
    $url = $mix->toUrl('canonical', ['absolute' => TRUE])->toString();
    $episode = $this->episodeNumber($mix);
    $artists = $this->artists($mix);

    $data = [
      '@context' => 'https://schema.org',
      '@type' => $episode !== NULL ? 'RadioEpisode' : 'MusicRecording',
      '@id' => $url . '#mix',
      'name' => $mix->label(),
      'url' => $url,
      'description' => $this->description($mix),
    ];
    if ($date = $this->date($mix, 'Y-m-d')) {
      $data['datePublished'] = $date;
    }
    if ($image = $this->coverUrl($mix)) {
      $data['image'] = $image;
    }
    if ($genres = $this->genres($mix)) {
      $data['genre'] = $genres;
    }
    if ($artists) {
      $people = array_map(fn($name) => $this->artist($name, $site), $artists);
      $data[$episode !== NULL ? 'contributor' : 'byArtist'] = $people;
    }
    if ($episode !== NULL) {
      $data['episodeNumber'] = $episode;
      $data['partOfSeries'] = [
        '@type' => 'RadioSeries',
        'name' => self::SERIES,
        'url' => $this->listingUrl() ?? $site,
      ];
      if ($this->onStation($mix)) {
        $data['publication'] = [
          '@type' => 'BroadcastEvent',
          'publishedOn' => ['@type' => 'BroadcastService', 'name' => self::STATION],
        ] + ($date ? ['startDate' => $date] : []);
      }
    }
    if ($audio = $this->audio($mix)) {
      $data['audio'] = $audio;
    }
    if ($same_as = $this->mixcloudUrls($mix)) {
      $data['sameAs'] = $same_as;
    }
    $data['isPartOf'] = ['@type' => 'WebSite', '@id' => $site . '#website', 'url' => $site];
    $data['publisher'] = ['@type' => 'Person', '@id' => $site . '#ibt', 'name' => 'Isaac Bigsby Trogdon'];
    return $data;
  }

  /**
   * The /llms.txt section listing every published mix, newest first.
   */
  public function llmsTxt(): string {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'dj_mix')
      ->condition('status', 1)
      ->sort('field_mix_date', 'DESC')
      ->sort('nid', 'DESC')
      ->execute();
    $lines = ['## DJ mixes', ''];
    foreach ($storage->loadMultiple($ids) as $mix) {
      $lines[] = sprintf('- [%s](%s): %s', $mix->label(), $mix->toUrl('canonical', ['absolute' => TRUE])->toString(), $this->description($mix));
    }
    return implode("\n", $lines);
  }

  /**
   * The absolute URL of the DJ mixes listing, NULL without the listing.
   */
  protected function listingUrl(): ?string {
    try {
      return Url::fromRoute('view.' . DjMixPager::VIEW . '.' . DjMixPager::DISPLAY, [], ['absolute' => TRUE])->toString();
    }
    catch (RouteNotFoundException) {
      return NULL;
    }
  }

  /**
   * The show and the artists, from "Show | Artists".
   *
   * @return string[]
   *   The show, and the artists or ''.
   */
  public function titleParts(NodeInterface $mix): array {
    [$show, $artists] = array_pad(explode(' | ', $mix->label(), 2), 2, '');
    return [trim($show), trim($artists)];
  }

  /**
   * The episode number of a Dyssembler Radio mix, NULL for other mixes.
   */
  public function episodeNumber(NodeInterface $mix): ?int {
    [$show] = $this->titleParts($mix);
    return preg_match('/^' . self::SERIES . ' #(\d+)$/', $show, $match) ? (int) $match[1] : NULL;
  }

  /**
   * The artists of a mix, from its title.
   *
   * The tags hold the artists' current names; the title the names they
   * played under, which is what the episode was.
   *
   * @return string[]
   *   Artist names.
   */
  public function artists(NodeInterface $mix): array {
    [, $artists] = $this->titleParts($mix);
    // E.g. "Novoline (Orinoco Flow takeover)": the note is not a name.
    $artists = preg_replace('/\s*\([^)]*\)/', '', $artists);
    $names = preg_split('/\s*(?:,|&| \/ )\s*/u', $artists, -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_unique(array_map('trim', $names)));
  }

  /**
   * The published musical genres of a mix.
   *
   * @return string[]
   *   Genre names.
   */
  public function genres(NodeInterface $mix): array {
    if (!$mix->hasField('field_musical_genres')) {
      return [];
    }
    $genres = array_filter($mix->get('field_musical_genres')->referencedEntities(), fn($term) => $term instanceof TermInterface && $term->isPublished());
    return array_values(array_map(fn($term) => $term->label(), $genres));
  }

  /**
   * The mix date in the given format, or '' when unknown.
   */
  protected function date(NodeInterface $mix, string $format): string {
    $item = $mix->get('field_mix_date')->first();
    return $item?->date ? $item->date->format($format) : '';
  }

  /**
   * Whether the mix was broadcast on Berlin Community Radio (by its tag).
   */
  protected function onStation(NodeInterface $mix): bool {
    if (!$mix->hasField('field_tags')) {
      return FALSE;
    }
    foreach ($mix->get('field_tags')->referencedEntities() as $tag) {
      if ($tag->label() === self::STATION) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * An artist as schema.org data; DJ Your Body is the site's own Person.
   */
  protected function artist(string $name, string $site): array {
    $artist = ['@type' => 'Person', 'name' => $name];
    if (in_array($name, ['DJ Your Body', 'Your Body', 'IBT', 'Isaac Bigsby Trogdon'], TRUE)) {
      $artist['@id'] = $site . '#ibt';
    }
    return $artist;
  }

  /**
   * The absolute URL of the mix's cover.
   */
  protected function coverUrl(NodeInterface $mix): string {
    $file = $mix->get('field_cover_image')->entity;
    return $file ? $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri()) : '';
  }

  /**
   * The parts the mix page plays, as AudioObjects.
   *
   * Its Mixcloud shows if it has any, else its MP3 tracks - as on the page.
   */
  protected function audio(NodeInterface $mix): array {
    $mixcloud = $mix->hasField('field_mixcloud') ? $mix->get('field_mixcloud')->referencedEntities() : [];
    $parts = $mixcloud ?: $mix->get('field_tracks')->referencedEntities();
    $audio = [];
    foreach ($parts as $part) {
      if ($part instanceof MediaInterface && $part->access('view')) {
        $audio[] = $mixcloud ? $this->mixcloudAudio($part) : $this->mp3Audio($part);
      }
    }
    return array_values(array_filter($audio));
  }

  /**
   * A Mixcloud show as an AudioObject.
   */
  protected function mixcloudAudio(MediaInterface $show): array {
    $audio = [
      '@type' => 'AudioObject',
      'name' => $show->label(),
      'url' => $show->getSource()->getSourceFieldValue($show),
    ];
    if ($show->hasField('field_mixcloud_duration') && ($seconds = (int) $show->get('field_mixcloud_duration')->value)) {
      $audio['duration'] = $this->isoDuration($seconds);
    }
    return $audio;
  }

  /**
   * An MP3 track as an AudioObject.
   */
  protected function mp3Audio(MediaInterface $track): array {
    $file = $track->hasField('field_media_mp3_file') ? $track->get('field_media_mp3_file')->entity : NULL;
    if (!$file) {
      return [];
    }
    $audio = [
      '@type' => 'AudioObject',
      'name' => $track->label(),
      'contentUrl' => $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri()),
      'encodingFormat' => 'audio/mpeg',
    ];
    if ($track->hasField('field_duration') && ($seconds = $this->seconds((string) $track->get('field_duration')->value))) {
      $audio['duration'] = $this->isoDuration($seconds);
    }
    return $audio;
  }

  /**
   * The mix's Mixcloud pages: the same mix elsewhere.
   *
   * @return string[]
   *   URLs.
   */
  protected function mixcloudUrls(NodeInterface $mix): array {
    if (!$mix->hasField('field_mixcloud_url')) {
      return [];
    }
    return array_values(array_filter(array_column($mix->get('field_mixcloud_url')->getValue(), 'uri')));
  }

  /**
   * Converts seconds to an ISO 8601 duration, e.g. 3818 -> PT1H3M38S.
   */
  public static function isoDuration(int $seconds): string {
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $rest = $seconds % 60;
    return 'PT' . ($hours ? "{$hours}H" : '') . ($minutes ? "{$minutes}M" : '') . ($rest || $seconds === 0 ? "{$rest}S" : '');
  }

  /**
   * Converts "1:03:38" or "52:31" to seconds; 0 when not a duration.
   */
  public static function seconds(string $duration): int {
    if (!preg_match('/^\d+(?::\d{1,2}){1,2}$/', trim($duration))) {
      return 0;
    }
    $seconds = 0;
    foreach (explode(':', trim($duration)) as $part) {
      $seconds = $seconds * 60 + (int) $part;
    }
    return $seconds;
  }

}
