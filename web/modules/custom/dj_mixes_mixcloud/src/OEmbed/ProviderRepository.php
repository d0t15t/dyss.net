<?php

namespace Drupal\dj_mixes_mixcloud\OEmbed;

use Drupal\media\OEmbed\ProviderRepositoryInterface;

/**
 * Finds oEmbed providers by name regardless of case.
 *
 * The providers list calls Mixcloud "MixCloud", but its oEmbed responses say
 * "Mixcloud", and core looks the provider up by the exact name in the
 * response. Decorates media.oembed.provider_repository.
 */
final class ProviderRepository implements ProviderRepositoryInterface {

  public function __construct(
    private readonly ProviderRepositoryInterface $inner,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getAll() {
    return $this->inner->getAll();
  }

  /**
   * {@inheritdoc}
   */
  public function get($provider_name) {
    try {
      return $this->inner->get($provider_name);
    }
    catch (\InvalidArgumentException $e) {
      foreach ($this->inner->getAll() as $name => $provider) {
        if (strcasecmp($name, (string) $provider_name) === 0) {
          return $provider;
        }
      }
      throw $e;
    }
  }

}
