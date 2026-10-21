# htmx navigation

Same-site navigation with htmx: link clicks and GET form submissions fetch
the new page and swap only the content area, so everything outside it - the
header, a media player such as the Persistent Player - stays as it is.

Built on Drupal core's htmx integration (`core/drupal.htmx`), which loads the
CSS, JS and settings the new page needs that the current one doesn't have and
attaches Drupal behaviors to the new content.

## Requirements for the active theme

The theme must render **one** element, on every front-end page, matching the
configured "Content selector" (default: `#pjax-content`) - the only part of
the page navigation replaces:

```twig
<header>...</header>
<div id="pjax-content">
  {{ page.content }}
</div>
<footer>...</footer>
```

Pages without it (e.g. administration pages in another theme) load normally.

## What it does

- Same-site link clicks and GET forms (search, filters) fetch the page with
  htmx and swap the inside of the content area. POST forms submit normally.
- History: the URL is pushed; back/forward fetch the page again. The title,
  body classes and active menu link follow, and the window scrolls to the top.
- While a page loads, `<html>` has the class `is-navigating` (a thin bar at
  the top of the window) and the content area `aria-busy="true"` and the class
  `is-loading` (dimmed after a moment).
- The swap uses a view transition (a short crossfade) where supported, unless
  the visitor prefers reduced motion.
- Resting the pointer on a link (or touching it) requests the page in the
  background - at most once a minute per page, three at a time - exactly as
  the click will, so Drupal's caches have it ready and the click is fast.
- BigPipe is skipped for htmx requests, as its streamed replacements would
  land outside the swapped content.
- Any failure (an error, a non-HTML response, a page without the content
  area) falls back to a full page load.

Core's htmx-assets.js attaches behaviors without waiting for newly added
scripts to load; this module attaches them again once they have.

## Exclusions

Links matching "Excluded path patterns" (e.g. `/admin*`), or inside an
element with `data-no-htmx-navigation` (the former `data-no-pjax` works too),
load normally. Configure the selector, the patterns and whether navigation is
enabled at `/admin/config/user-interface/htmx-navigation`.

## Events

Dispatched on `document`:

| Event                       | `detail` | Notes |
|-----------------------------|----------|-------|
| `htmxnavigation:navigate`   | `{url}`  | Cancelable. Fired before a navigation; call `preventDefault()` for a normal page load instead. |
| `htmxnavigation:navigated`  | `{url}`  | Fired after a navigation, once the new page's assets are loaded and behaviors attached. |

## License

GPL-2.0-or-later, consistent with Drupal core.
