# Persistent Player

A persistent media player and same-site AJAX ("pjax") navigation layer, so
playback survives the visitor navigating to a new page.

## Why

A real full-page HTTP navigation always tears down the whole page, including
any playing `<audio>` element - no amount of JavaScript on the destination
page can prevent that. The only way to make playback survive navigation is to
never actually navigate: intercept same-site link clicks, fetch the
destination page, and swap only the part of the DOM that changes per route,
leaving the player untouched in a part of the page that's never replaced.

This module has no knowledge of any particular content type. It doesn't know
what a "track" or a "playlist" is - it just plays one media item at a time and
tells the rest of the site what happened via events, so any feature (a
tracklist, a podcast episode list, a single "listen" button) can build on it.

## Requirements for the active theme

The theme must render **one** element, on every page, matching the configured
"Content selector" (default: `#pjax-content`) - this is the only part of the
page that same-site navigation is allowed to replace. Everything outside it
(header, navigation, footer, and wherever you place the "Persistent media
player" block) is left alone across a transition.

```twig
<header>...</header>
<div id="pjax-content">
  {{ page.content }}
</div>
<footer>...</footer>
```

Place the "Persistent media player" block (`persistent_player` plugin) in any
region **outside** that wrapper.

Configure the selector, and whether pjax navigation is enabled at all, at
`/admin/config/media/persistent-player`.

## JavaScript API

```js
Drupal.persistentPlayer.play({
  id: 123,           // Anything unique and stable for this item.
  src: '/files/track.mp3',
  title: 'Track title',
  subtitle: 'Artist name',
  artwork: '/files/cover.jpg', // optional
  mediaType: 'audio',
});

Drupal.persistentPlayer.pause();
Drupal.persistentPlayer.stop();
Drupal.persistentPlayer.getCurrent(); // -> the current item, or null
```

Calling `play()` again with the same `id` toggles play/pause instead of
restarting the item.

## Events

Dispatched on `document`:

| Event                          | `detail`                                  | Notes |
|---------------------------------|--------------------------------------------|-------|
| `persistentplayer:play`         | `{item}`                                    | |
| `persistentplayer:pause`        | `{item}`                                    | |
| `persistentplayer:stop`         | `{item}`                                    | |
| `persistentplayer:ended`        | `{item}`                                    | Listen here to implement "play next" - this module has no playlist concept of its own. |
| `persistentplayer:timeupdate`   | `{item, currentTime, duration}`             | |
| `persistentplayer:navigate`     | `{url}`                                     | Cancelable. Fired before a pjax swap; call `preventDefault()` to force a normal navigation for that one link. |
| `persistentplayer:navigated`    | `{url}`                                     | Fired after a pjax swap completes and behaviors are reattached. |

## Limitations

A typed URL, a bookmark, a hard refresh, or an external link back into the
site is a real browser navigation. This module cannot intercept those, and
playback will stop - that's a platform limitation, not a bug. If you need
links to be excluded from pjax navigation for other reasons (e.g. a download
link, or a route with its own full-page behavior), add `data-no-pjax` to the
link, or add a path pattern to "Excluded path patterns" in the settings form.

## License

GPL-2.0-or-later, consistent with Drupal core.
