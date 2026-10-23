<?php

namespace Drupal\dj_mixes;

use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Drupal\views\Plugin\views\sort\SortPluginBase;
use Drupal\views\ViewExecutable;
use Drupal\views\ViewExecutableFactory;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The DJ mixes listing's order, for paging from one mix to its neighbours.
 *
 * The order and selection are those of the "DJ mixes" search page
 * (views.view.dj_mixes, display page_1). Its exposed input - filters and,
 * when exposed, sorting - is carried in the URL from the listing to a mix
 * and from mix to mix, so paging follows the search the visitor came from.
 */
class DjMixPager {

  /**
   * The listing view and display.
   */
  const VIEW = 'dj_mixes';
  const DISPLAY = 'page_1';

  /**
   * Upper bound on listed mixes.
   *
   * "All items" (0) leaves the Search API query without a limit, and Solr
   * then returns its default of 10 rows.
   */
  const MAX_ITEMS = 5000;

  /**
   * DJ mix view modes whose links carry the listing parameters.
   */
  const LINK_VIEW_MODES = ['teaser', 'card'];

  /**
   * Infinite scroll: the teaser this far from the end loads the next page.
   *
   * Loading starts a little before the visitor reaches the last teaser.
   */
  const SCROLL_PREFETCH = 4;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ViewExecutableFactory $viewExecutableFactory,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * The URL parameters the listing accepts as exposed input.
   *
   * @return string[]
   *   Exposed filter (and operator) identifiers, plus sort_by and sort_order
   *   when the listing exposes sorts.
   */
  public function parameterNames(): array {
    $view = $this->view();
    if (!$view) {
      return [];
    }
    $names = [];
    foreach ($view->display_handler->getHandlers('filter') as $filter) {
      if ($filter instanceof FilterPluginBase && $filter->isExposed()) {
        $names[] = $filter->options['expose']['identifier'];
        if (!empty($filter->options['expose']['use_operator'])) {
          $names[] = $filter->options['expose']['operator_id'];
        }
      }
    }
    foreach ($view->display_handler->getHandlers('sort') as $sort) {
      if ($sort instanceof SortPluginBase && $sort->isExposed()) {
        array_push($names, 'sort_by', 'sort_order');
        break;
      }
    }
    return array_values(array_unique(array_filter($names)));
  }

  /**
   * The listing parameters in a request, without empty values.
   *
   * @return array<string, mixed>
   *   Query parameters to pass on.
   */
  public function parameters(Request $request): array {
    $parameters = [];
    foreach ($this->parameterNames() as $name) {
      $value = $request->query->all()[$name] ?? NULL;
      if ($value !== NULL && $value !== '' && $value !== []) {
        $parameters[$name] = $value;
      }
    }
    return $parameters;
  }

  /**
   * The mixes around a mix in the listing's order.
   *
   * @param int $nid
   *   The current mix.
   * @param array $parameters
   *   The listing parameters (see parameters()).
   * @param int $count
   *   How many mixes to return on each side.
   *
   * @return array|null
   *   NULL when the mix is not listed at all, else:
   *   - before: node IDs listed before the mix, nearest last.
   *   - after: node IDs listed after the mix, nearest first.
   *   - position: the mix's 1-based position.
   *   - total: the number of listed mixes.
   *   - parameters: the parameters used; empty when the mix is not in the
   *     filtered listing and the default order was used instead.
   */
  public function neighbours(int $nid, array $parameters, int $count = 10): ?array {
    $window = self::window($this->listedIds($parameters), $nid, $count);
    if (!$window && $parameters) {
      $parameters = [];
      $window = self::window($this->listedIds([]), $nid, $count);
    }
    return $window ? $window + ['parameters' => $parameters] : NULL;
  }

  /**
   * The IDs around one ID in a list.
   *
   * @param int[] $ids
   *   The listed IDs, in order.
   * @param int $id
   *   The current ID.
   * @param int $count
   *   How many IDs to return on each side.
   *
   * @return array|null
   *   NULL when $id is not in $ids, else before, after, position and total
   *   as in neighbours().
   */
  public static function window(array $ids, int $id, int $count): ?array {
    $index = array_search($id, $ids, TRUE);
    if ($index === FALSE) {
      return NULL;
    }
    return [
      'before' => array_slice($ids, max(0, $index - $count), min($count, $index)),
      'after' => array_slice($ids, $index + 1, $count),
      'position' => $index + 1,
      'total' => count($ids),
    ];
  }

  /**
   * The query for the listing page after the current one.
   *
   * @param array $parameters
   *   The listing's exposed input.
   * @param int $page
   *   The current page (0-based).
   * @param int $per_page
   *   Items per page.
   * @param int $total
   *   Items in the listing.
   *
   * @return array|null
   *   The listing parameters with the next page number, or NULL on the last
   *   page.
   */
  public static function nextPageQuery(array $parameters, int $page, int $per_page, int $total): ?array {
    if ($per_page < 1 || ($page + 1) * $per_page >= $total) {
      return NULL;
    }
    unset($parameters['page']);
    return $parameters + ['page' => $page + 1];
  }

  /**
   * All node IDs the listing shows for the given parameters, in order.
   *
   * @return int[]
   *   Node IDs.
   */
  public function listedIds(array $parameters): array {
    $view = $this->view();
    if (!$view) {
      return [];
    }
    // Views falls back to the current request's query for empty exposed
    // input (and reads the page number from it): give the view a request of
    // its own, so only these parameters count.
    $request = Request::create('/', 'GET', $parameters);
    $current = $this->requestStack->getCurrentRequest();
    if ($current?->hasSession()) {
      $request->setSession($current->getSession());
    }
    $view->setRequest($request);
    $view->setExposedInput($parameters);
    $view->setItemsPerPage(self::MAX_ITEMS);
    $view->setCurrentPage(0);
    // The facets filter writes its value into the current request (and
    // $_GET) rather than the view's: make the view's request the current one
    // meanwhile, so the page's own query is left alone.
    // phpcs:ignore DrupalPractice.Variables.GetRequestData
    $get = $_GET;
    $this->requestStack->push($request);
    try {
      $view->execute();
    }
    finally {
      $this->requestStack->pop();
      // phpcs:ignore DrupalPractice.Variables.GetRequestData
      $_GET = $get;
    }
    $ids = [];
    foreach ($view->result as $row) {
      $entity = $row->_object?->getValue();
      if ($entity && $entity->getEntityTypeId() === 'node') {
        $ids[] = (int) $entity->id();
      }
    }
    $view->destroy();
    return $ids;
  }

  /**
   * The listing view, set to its page display.
   */
  private function view(): ?ViewExecutable {
    $storage = $this->entityTypeManager->getStorage('view')->load(self::VIEW);
    if (!$storage) {
      return NULL;
    }
    $view = $this->viewExecutableFactory->get($storage);
    return $view->setDisplay(self::DISPLAY) ? $view : NULL;
  }

}
