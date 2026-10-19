<?php

namespace Drupal\dj_mixes_mixcloud;

/**
 * Reads a tracklist from a Mixcloud show description.
 *
 * Mixcloud's API has no tracklists for these shows, but some descriptions
 * list the tracks as "Artist - Title" lines.
 */
final class Tracklist {

  /**
   * Fewer "Artist - Title" lines than this are not taken for a tracklist.
   */
  const MIN_TRACKS = 3;

  /**
   * The API cuts descriptions off at this length.
   */
  const API_MAX_LENGTH = 1000;

  /**
   * Parses the tracks in a description.
   *
   * @param string $description
   *   The show description.
   *
   * @return string[]
   *   Tracks as "Artist – Title", in order; empty when the description has
   *   no tracklist.
   */
  public static function parse(string $description): array {
    $lines = preg_split('/\R/u', trim($description));
    // The last line of a cut-off description may be incomplete.
    if (mb_strlen($description) >= self::API_MAX_LENGTH) {
      array_pop($lines);
    }
    $tracks = [];
    foreach ($lines as $line) {
      if ($track = self::track($line)) {
        $tracks[] = $track;
      }
    }
    return count($tracks) >= self::MIN_TRACKS ? $tracks : [];
  }

  /**
   * Reads one "Artist - Title" line, or NULL when the line is no track.
   */
  public static function track(string $line): ?string {
    $line = trim($line);
    if ($line === '' || preg_match('~https?://|www\.~i', $line)) {
      return NULL;
    }
    // Leading numbers and times: "1.", "01)", "12 -", "[00:12:34]".
    $line = preg_replace('/^(\[?\d{1,2}(:\d{2}){1,2}\]?\s*|\d{1,3}\s*[.)]\s*|\d{1,3}\s+[-–—]\s+)/u', '', $line);
    if (!preg_match('/^(.{1,120}?)\s+[-–—]+\s+(.{1,200})$/u', $line, $match)) {
      return NULL;
    }
    [, $artist, $title] = array_map('trim', $match);
    // A title without a letter or digit is a dash in a comment, not a track.
    if ($artist === '' || !preg_match('/[\p{L}\p{N}]/u', $title) || mb_strlen($artist) > 80) {
      return NULL;
    }
    return $artist . ' – ' . $title;
  }

}
