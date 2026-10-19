<?php

namespace Drupal\persistent_player\Render;

use Drupal\Core\Render\Placeholder\PlaceholderStrategyInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Keeps BigPipe out of htmx requests.
 *
 * Navigation swaps only the content area of a fetched page; BigPipe's
 * replacements for streamed placeholders arrive outside it and would be
 * lost, leaving those parts empty. For htmx requests the placeholders are
 * rendered in place instead. Decorates placeholder_strategy.big_pipe.
 */
final class HtmxBigPipeStrategy implements PlaceholderStrategyInterface {

  public function __construct(
    private readonly PlaceholderStrategyInterface $inner,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function processPlaceholders(array $placeholders) {
    if ($this->requestStack->getCurrentRequest()?->headers->get('HX-Request') === 'true') {
      return [];
    }
    return $this->inner->processPlaceholders($placeholders);
  }

}
