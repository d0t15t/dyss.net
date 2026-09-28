/**
 * @file
 * Same-site AJAX ("pjax") navigation.
 *
 * Intercepts clicks on internal links, fetches the destination page, and
 * swaps only the element matched by drupalSettings.persistentPlayer's
 * `contentSelector` - everything outside it (including wherever the
 * persistent player block is placed) is never touched, so playback survives
 * the transition.
 *
 * This is a progressive enhancement: any link, response, or condition this
 * cannot safely handle falls back to a normal browser navigation. A typed
 * URL, bookmark, hard refresh, or an external link back into the site is a
 * real navigation this script never sees, and will stop playback - that is
 * an unavoidable browser limitation, not a bug.
 *
 * Dispatches on `document`:
 * - `persistentplayer:navigate`  (cancelable) detail: {url} - before a swap.
 *   Call preventDefault() on this event to force a normal navigation for
 *   that one transition.
 * - `persistentplayer:navigated` detail: {url} - after a swap completes.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  const settings = drupalSettings.persistentPlayer || {};
  const contentSelector = settings.contentSelector || '#pjax-content';
  const excludedPathPatterns = settings.excludedPathPatterns || [];

  function pathMatchesPattern(pattern, path) {
    const regex = new RegExp(
      '^' + pattern.replace(/[.+^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*') + '$',
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

  function navigateTo(url, { push }) {
    const navigateEvent = new CustomEvent('persistentplayer:navigate', {
      detail: { url },
      cancelable: true,
    });
    document.dispatchEvent(navigateEvent);
    if (navigateEvent.defaultPrevented) {
      window.location.href = url;
      return;
    }

    fetch(url, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
      .then((response) => {
        const contentType = response.headers.get('content-type') || '';
        if (!response.ok || contentType.indexOf('text/html') === -1) {
          throw new Error('Unusable response');
        }
        return response.text();
      })
      .then((html) => {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const newContent = doc.querySelector(contentSelector);
        const oldContent = document.querySelector(contentSelector);
        if (!newContent || !oldContent) {
          throw new Error('Content selector not found');
        }

        Drupal.detachBehaviors(oldContent, drupalSettings, 'unload');
        oldContent.innerHTML = newContent.innerHTML;
        document.title = doc.title;
        document.body.className = doc.body.className;

        if (push) {
          window.history.pushState({ persistentPlayerNav: true }, '', url);
        }
        window.scrollTo(0, 0);
        updateActiveNav();
        Drupal.attachBehaviors(oldContent, drupalSettings);

        document.dispatchEvent(new CustomEvent('persistentplayer:navigated', { detail: { url } }));
      })
      .catch(() => {
        window.location.href = url;
      });
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
    return true;
  }

  Drupal.behaviors.persistentPlayerPjaxNav = {
    attach(context) {
      if (!settings.enabledPjaxNav) {
        return;
      }
      once('persistent-player-pjax-nav', 'html', context).forEach(() => {
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
})(Drupal, drupalSettings, once);
