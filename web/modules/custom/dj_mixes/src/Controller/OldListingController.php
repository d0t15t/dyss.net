<?php

namespace Drupal\dj_mixes\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\dj_mixes\DjMixPager;
use Symfony\Component\HttpFoundation\Request;

/**
 * Sends the listing's old path, /dj-mixes, to the listing at /mixes.
 */
class OldListingController {

  /**
   * A permanent redirect to the listing, same language and filters.
   */
  public function redirectToListing(Request $request): TrustedRedirectResponse {
    $url = Url::fromRoute('view.' . DjMixPager::VIEW . '.' . DjMixPager::DISPLAY, [], [
      'absolute' => TRUE,
      'query' => $request->query->all(),
    ])->toString();
    $response = new TrustedRedirectResponse($url, 301);
    $cacheability = (new CacheableMetadata())
      ->setCacheContexts(['url.query_args', 'languages:language_url']);
    $response->addCacheableDependency($cacheability);
    return $response;
  }

}
