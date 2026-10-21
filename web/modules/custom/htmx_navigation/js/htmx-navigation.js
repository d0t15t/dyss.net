/**
 * @file
 * Same-site navigation with htmx.
 *
 * Same-site link clicks fetch the new page with htmx and swap only the
 * content area (drupalSettings.htmxNavigation.contentSelector, by default
 * #pjax-content); everything outside it - e.g. a media player - stays.
 * Drupal's htmx integration (core/drupal.htmx) loads the CSS, JS and
 * settings the new page needs and attaches behaviors to it, which a plain
 * swap of HTML could not.
 *
 * GET forms (e.g. search filters) navigate the same way. While a page loads,
 * <html> has the "is-navigating" class and the content area aria-busy; the
 * swap uses a view transition where supported (not with reduced motion).
 * Resting on a link warms the server's caches for exactly the request a
 * click will make, so the page comes back fast.
 *
 * Links matching an excluded path pattern or inside [data-no-htmx-navigation]
 * (or the former [data-no-pjax]) load normally. History (back/forward fetch
 * the page again), the document title, body classes, the active menu link
 * and scrolling to the top follow the new page. The cancelable
 * `htmxnavigation:navigate` and `htmxnavigation:navigated` events are
 * dispatched on document. Any failure falls back to a full page load.
 */
((Drupal, drupalSettings, htmx, once) => {
  const settings = drupalSettings.htmxNavigation || {};
  const contentSelector = settings.contentSelector || '#pjax-content';
  const excludedPathPatterns = settings.excludedPathPatterns || [];
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

  // Query parameters Drupal's htmx integration adds to requests.
  const internalParameters = [
    '_wrapper_format',
    'ajax_page_state[theme]',
    'ajax_page_state[theme_token]',
    'ajax_page_state[libraries]',
  ];

  // The navigation in progress: { url, push, bodyClass }.
  let pending = null;

  // Drupal core's htmx integration attaches behaviors without waiting for the
  // assets of the response to load (htmx-assets.js does not keep the promise
  // of Drupal.htmx.addAssets()), so behaviors in newly loaded scripts would
  // miss the new content. Keep that promise to attach them once loaded.
  let assetsLoaded = Promise.resolve();
  const { addAssets } = Drupal.htmx;
  Drupal.htmx.addAssets = function addAssetsAndRemember(...args) {
    assetsLoaded = Promise.resolve(addAssets.apply(this, args));
    return assetsLoaded;
  };

  function pathMatchesPattern(pattern, path) {
    const regex = new RegExp(
      `^${pattern.replace(/[.+^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*')}$`,
    );
    return regex.test(path);
  }

  function isExcludedPath(pathname) {
    return excludedPathPatterns.some((pattern) => pathMatchesPattern(pattern, pathname));
  }

  function updateActiveNav() {
    const current = window.location.pathname;
    document.querySelectorAll('a[href]').forEach((link) => {
      if (link.closest(contentSelector)) {
        return;
      }
      const isActive = link.pathname === current;
      link.classList.toggle('is-active', isActive);
      if (isActive) {
        link.setAttribute('aria-current', 'page');
      }
      else {
        link.removeAttribute('aria-current');
      }
    });
  }

  function cleanUrl(url) {
    const clean = new URL(url, window.location.href);
    internalParameters.forEach((key) => clean.searchParams.delete(key));
    return clean.toString();
  }

  function fullLoad(url) {
    pending = null;
    window.location.href = url;
  }

  function navigateTo(url, { push }) {
    const navigateEvent = new CustomEvent('htmxnavigation:navigate', {
      detail: { url },
      cancelable: true,
    });
    document.dispatchEvent(navigateEvent);
    const content = document.querySelector(contentSelector);
    if (navigateEvent.defaultPrevented || !content) {
      fullLoad(url);
      return;
    }
    pending = { url, push, bodyClass: null };
    setBusy(true);
    const transition = reducedMotion.matches ? '' : ' transition:true';
    // The content element itself stays, so behaviors and assets are handled
    // for it by Drupal's htmx integration once the new children are in.
    htmx
      .ajax('GET', url, {
        source: content,
        target: content,
        select: `${contentSelector} > *`,
        swap: `innerHTML show:window:top${transition}`,
      })
      .catch(() => fullLoad(url));
  }

  function setBusy(busy) {
    document.documentElement.classList.toggle('is-navigating', busy);
    const content = document.querySelector(contentSelector);
    if (content) {
      content.setAttribute('aria-busy', busy ? 'true' : 'false');
      content.classList.toggle('is-loading', busy);
    }
  }

  /**
   * The URL of a GET form submission, or NULL when it navigates elsewhere.
   */
  function formUrl(form, submitter) {
    const method = (submitter?.getAttribute('formmethod') || form.getAttribute('method') || 'get').toLowerCase();
    if (method !== 'get' || form.closest('[data-no-htmx-navigation], [data-no-pjax]')) {
      return null;
    }
    const target = submitter?.getAttribute('formtarget') || form.getAttribute('target');
    if (target && target !== '_self') {
      return null;
    }
    const url = new URL(submitter?.getAttribute('formaction') || form.getAttribute('action') || window.location.href, window.location.href);
    if (url.origin !== window.location.origin || isExcludedPath(url.pathname)) {
      return null;
    }
    const query = new URLSearchParams();
    new FormData(form, submitter).forEach((value, key) => {
      if (typeof value === 'string') {
        query.append(key, value);
      }
    });
    url.search = query.toString();
    url.hash = '';
    return url.toString();
  }

  /**
   * The URL Drupal's htmx integration requests for a page.
   *
   * The same parameters, in the same order and encoding as htmx adds them,
   * so that a preload warms the cache entry the click will hit.
   */
  function requestUrl(url) {
    const pageState = drupalSettings.ajaxPageState || {};
    const parameters = [
      ['ajax_page_state[theme]', pageState.theme],
      ['ajax_page_state[theme_token]', pageState.theme_token],
      ['ajax_page_state[libraries]', pageState.libraries],
    ]
      // As FormData does: a missing value becomes "null" or "undefined".
      .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`)
      .join('&');
    const [path, anchor] = url.split('#');
    return `${path}${path.includes('?') ? '&' : '?'}${parameters}${anchor ? `#${anchor}` : ''}`;
  }

  const preloaded = new Map();
  let preloading = 0;

  /**
   * Requests a page ahead of a click, once a minute at most and three at a
   * time, so the server has it cached when the visitor clicks.
   */
  function preload(url) {
    const now = Date.now();
    if ((preloaded.get(url) || 0) > now - 60000 || preloading >= 3) {
      return;
    }
    preloaded.set(url, now);
    preloading += 1;
    fetch(requestUrl(url), {
      credentials: 'same-origin',
      headers: { 'HX-Request': 'true' },
    })
      .catch(() => {})
      .finally(() => {
        preloading -= 1;
      });
  }

  function shouldIntercept(event, link) {
    if (event.defaultPrevented || event.button !== 0) {
      return false;
    }
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return false;
    }
    return isNavigable(link);
  }

  /**
   * Whether a link is handled by this navigation.
   */
  function isNavigable(link) {
    if (!link || link.closest('[data-no-htmx-navigation], [data-no-pjax]')) {
      return false;
    }
    if (link.target && link.target !== '_self') {
      return false;
    }
    if (link.hasAttribute('download')) {
      return false;
    }
    if (link.origin !== window.location.origin) {
      return false;
    }
    if (link.pathname === window.location.pathname && link.hash) {
      return false;
    }
    if (isExcludedPath(link.pathname)) {
      return false;
    }
    // Links that make their own htmx requests.
    if (link.matches('[hx-get], [data-hx-get]')) {
      return false;
    }
    return true;
  }

  htmx.on('htmx:beforeSwap', ({ detail }) => {
    if (!pending || detail.target !== document.querySelector(contentSelector)) {
      return;
    }
    const { xhr } = detail;
    const type = (xhr && xhr.getResponseHeader('content-type')) || '';
    if (!xhr || xhr.status !== 200 || !type.includes('text/html')) {
      detail.shouldSwap = false;
      fullLoad(pending.url);
      return;
    }
    const doc = new DOMParser().parseFromString(detail.serverResponse, 'text/html');
    if (!doc.querySelector(contentSelector)) {
      // E.g. an admin page in another theme.
      detail.shouldSwap = false;
      fullLoad(cleanUrl(xhr.responseURL || pending.url));
      return;
    }
    pending.bodyClass = doc.body.className;
    pending.url = cleanUrl(xhr.responseURL || pending.url);
  });

  htmx.on('htmx:afterSwap', ({ detail }) => {
    if (!pending || detail.target !== document.querySelector(contentSelector)) {
      return;
    }
    document.body.className = pending.bodyClass;
    if (pending.push) {
      window.history.pushState({ htmxNavigation: true }, '', pending.url);
    }
    updateActiveNav();
  });

  // Fired once the new content is in; behaviors of scripts still loading are
  // attached when they have loaded (once() skips anything already attached).
  htmx.on('htmx:drupal:load', () => {
    if (!pending || pending.bodyClass === null) {
      return;
    }
    const { url } = pending;
    pending = null;
    setBusy(false);
    assetsLoaded.then(() => {
      const content = document.querySelector(contentSelector);
      if (content) {
        Drupal.attachBehaviors(content, drupalSettings);
      }
      document.dispatchEvent(new CustomEvent('htmxnavigation:navigated', { detail: { url } }));
    });
  });

  ['htmx:sendError', 'htmx:responseError', 'htmx:swapError'].forEach((name) => {
    htmx.on(name, () => {
      if (pending) {
        fullLoad(pending.url);
      }
    });
  });

  Drupal.behaviors.htmxNavigation = {
    attach(context) {
      // Without the content area there is nothing to swap.
      if (!document.querySelector(contentSelector)) {
        return;
      }
      once('htmx-navigation', 'html', context).forEach(() => {
        document.addEventListener('click', (event) => {
          const link = event.target.closest('a[href]');
          if (!shouldIntercept(event, link)) {
            return;
          }
          event.preventDefault();
          navigateTo(link.href, { push: true });
        });
        document.addEventListener('submit', (event) => {
          if (event.defaultPrevented) {
            return;
          }
          const url = formUrl(event.target, event.submitter);
          if (url) {
            event.preventDefault();
            navigateTo(url, { push: true });
          }
        });
        window.addEventListener('popstate', () => {
          navigateTo(window.location.href, { push: false });
        });

        // Preload after resting on a link briefly, or right away on touch.
        let hoverTimer = null;
        document.addEventListener('pointerover', (event) => {
          const link = event.target.closest?.('a[href]');
          clearTimeout(hoverTimer);
          if (link && isNavigable(link) && link.href !== window.location.href) {
            hoverTimer = setTimeout(() => preload(link.href), event.pointerType === 'mouse' ? 100 : 0);
          }
        });
        document.addEventListener('pointerout', () => clearTimeout(hoverTimer));
      });
    },
  };
})(Drupal, drupalSettings, htmx, once);
