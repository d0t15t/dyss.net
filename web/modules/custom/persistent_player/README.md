# Persistent Player

A page-wide media player that keeps playing while the visitor navigates the
site. It plays MP3s itself, and embeddable media - e.g. Mixcloud shows - via
backends other modules add.

## Why

A real full-page HTTP navigation always tears down the whole page, including
any playing media - no amount of JavaScript on the destination page can
prevent that. Playback survives only if the page is never really left: the
htmx navigation module (a dependency) swaps only the content area of a page,
and this player lives outside it.

This module has no knowledge of any particular content type. It doesn't know
what a "track" or a "playlist" is - it plays one media item at a time and
tells the rest of the site what happened via events, so any feature (a
tracklist, a podcast episode list, a single "listen" button) can build on it.

## Setup

Place the "Persistent media player" block (`persistent_player` plugin) in a
region **outside** the content area htmx navigation swaps (see the
htmx_navigation README).

## JavaScript API

```js
Drupal.persistentPlayer.play({
  id: 123,           // Anything unique and stable for this item.
  src: '/files/track.mp3',
  title: 'Track title',
  subtitle: 'Artist name',
  artwork: '/files/cover.jpg', // optional
  mediaType: 'audio', // the backend that plays it, e.g. 'mixcloud'
});

Drupal.persistentPlayer.pause();
Drupal.persistentPlayer.stop();
Drupal.persistentPlayer.getCurrent(); // -> the current item, or null
```

Calling `play()` again with the same `id` toggles play/pause instead of
restarting the item.

## Backends

`mediaType` names the backend that plays an item. `audio` (an `<audio>`
element) is built in; other modules register more:

```js
Drupal.persistentPlayer.registerBackend('mixcloud', (embed, report) => ({
  embedded: true,      // Shows its own player in `embed`.
  load(item) { ... },  // Load and start playing.
  play() { ... },
  pause() { ... },
  isPaused() { ... },
  unload() { ... },    // Stop and remove what it loaded.
}));
```

`embed` is a container in the player bar; while an `embedded` backend plays,
the bar hides its own play/pause, artwork, title and progress (the stop
button stays). The backend reports through `report.play()`, `report.pause()`,
`report.ended()` and `report.time(currentTime, duration)`, which the player
turns into the events below. dj_mixes_mixcloud's `mixcloud` backend embeds
Mixcloud's widget and follows it through the Mixcloud Widget API.

## Events

Dispatched on `document`:

| Event                          | `detail`                                  | Notes |
|---------------------------------|--------------------------------------------|-------|
| `persistentplayer:play`         | `{item}`                                    | |
| `persistentplayer:pause`        | `{item}`                                    | |
| `persistentplayer:stop`         | `{item}`                                    | |
| `persistentplayer:ended`        | `{item}`                                    | Listen here to implement "play next" - this module has no playlist concept of its own. |
| `persistentplayer:timeupdate`   | `{item, currentTime, duration}`             | |

## Limitations

A typed URL, a bookmark, a hard refresh, an excluded link, or an external
link back into the site is a real browser navigation, and playback will
stop - that's a platform limitation, not a bug.

## License

GPL-2.0-or-later, consistent with Drupal core.
