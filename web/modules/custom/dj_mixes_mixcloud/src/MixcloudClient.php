<?php

namespace Drupal\dj_mixes_mixcloud;

use GuzzleHttp\ClientInterface;

/**
 * Reads public data from the Mixcloud API (no key needed).
 */
final class MixcloudClient {

  const API = 'https://api.mixcloud.com';

  public function __construct(
    private readonly ClientInterface $httpClient,
  ) {}

  /**
   * Lists an account's uploads (cloudcasts), following the API's paging.
   *
   * @param string $username
   *   The Mixcloud username, e.g. "BCR_Radio".
   *
   * @return array[]
   *   The list entries; they lack the description and cover colour.
   */
  public function listCloudcasts(string $username): array {
    $url = self::API . '/' . rawurlencode($username) . '/cloudcasts/?limit=100';
    $cloudcasts = [];
    while ($url) {
      $page = $this->get($url);
      array_push($cloudcasts, ...$page['data'] ?? []);
      $url = $page['paging']['next'] ?? NULL;
    }
    return $cloudcasts;
  }

  /**
   * Fetches one upload with all its fields.
   *
   * @param string $key
   *   The cloudcast key, e.g. "/BCR_Radio/dyssembler-1/".
   */
  public function getCloudcast(string $key): array {
    return $this->get(self::API . $key);
  }

  /**
   * GETs a JSON resource, retrying when rate limited.
   */
  private function get(string $url): array {
    $attempt = 0;
    while (TRUE) {
      $attempt++;
      $response = $this->httpClient->request('GET', $url, ['http_errors' => FALSE, 'timeout' => 60]);
      if ($response->getStatusCode() === 403 && $attempt < 4) {
        // Mixcloud answers 403 when rate limited, with the wait in the body.
        $body = json_decode((string) $response->getBody(), TRUE);
        sleep(min(60, (int) ($body['error']['retry_after'] ?? 10)));
        continue;
      }
      if ($response->getStatusCode() !== 200) {
        throw new \RuntimeException(sprintf('Mixcloud API returned %d for %s.', $response->getStatusCode(), $url));
      }
      return json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
  }

}
