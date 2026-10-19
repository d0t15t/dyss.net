/**
 * @file
 * Same-site navigation with htmx, so the persistent player keeps playing.
 *
 * Same-site link clicks fetch the new page with htmx and swap only the
 * content area (drupalSettings.persistentPlayer.contentSelector, by default
 * #pjax-content); everything outside it - the player included - stays.
 * Drupal's htmx integration (core/drupal.htmx) loads the CSS, JS and
 * settings the new page needs and attaches behaviors to it, which a plain
 * swap of HTML could not.
 *
 * Kept from the former pjax navigation: excluded paths and [data-no-pjax],
 * history (back/forward fetch the page again), the document title, body
 * classes, the active menu link, scrolling to the top, and the cancelable
 * `persistentplayer:navigate` and `persistentplayer:navigated` events. Any
 * failure falls back to a full page load.
 */
((Drupal, drupalSettings, htmx, once) => {
  const settings = drupalSettings.persistentPlayer || {};
  const contentSelector = settings.contentSelector || '#pjax-content';
  const excludedPathPatterns = settings.excludedPathPatterns || [];

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
    const navigateEvent = new CustomEvent('persistentplayer:navigate', {
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
    // The content element itself stays, so behaviors and assets are handled
    // for it by Drupal's htmx integration once the new children are in.
    htmx
      .ajax('GET', url, {
        source: content,
        target: content,
        select: `${contentSelector} > *`,
        swap: 'innerHTML show:window:top',
      })
      .catch(() => fullLoad(url));
  }

  function shouldIntercept(event, link) {
    if (event.defaultPrevented || event.button !== 0) {
      return false;
    }
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return false;
    }
    if (!link || link.closest('[data-no-pjax]')) {
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
      window.history.pushState({ persistentPlayerNav: true }, '', pending.url);
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
    assetsLoaded.then(() => {
      const content = document.querySelector(contentSelector);
      if (content) {
        Drupal.attachBehaviors(content, drupalSettings);
      }
      document.dispatchEvent(new CustomEvent('persistentplayer:navigated', { detail: { url } }));
    });
  });

  ['htmx:sendError', 'htmx:responseError', 'htmx:swapError'].forEach((name) => {
    htmx.on(name, () => {
      if (pending) {
        fullLoad(pending.url);
      }
    });
  });

  Drupal.behaviors.persistentPlayerHtmxNav = {
    attach(context) {
      if (!settings.enabledPjaxNav) {
        return;
      }
      once('persistent-player-htmx-nav', 'html', context).forEach(() => {
        document.addEventListener('click', (event) => {
          const link = event.target.closest('a[href]');
          if (!shouldIntercept(event, link)) {
            return;
          }
          event.preventDefault();
          navigateTo(link.href, { push: true });
        });
        window.addEventListener('popstate', () => {
          navigateTo(window.location.href, { push: false });
        });
      });
    },
  };
})(Drupal, drupalSettings, htmx, once);
