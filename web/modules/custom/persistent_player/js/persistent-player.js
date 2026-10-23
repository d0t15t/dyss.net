/**
 * @file
 * The persistent media player public API.
 *
 * Exposes `Drupal.persistentPlayer` with `play()`, `pause()`, `stop()`,
 * `getCurrent()` and `registerBackend()`. Other modules should not reach
 * into the DOM directly - depend on this API and the `persistentplayer:*`
 * events it dispatches on `document` instead:
 *
 * - `persistentplayer:play`      detail: {item}
 * - `persistentplayer:pause`     detail: {item}
 * - `persistentplayer:stop`      detail: {item}
 * - `persistentplayer:ended`     detail: {item}
 * - `persistentplayer:timeupdate` detail: {item, currentTime, duration}
 *
 * This module has no concept of a "playlist" or "next item" - a consumer
 * (e.g. a content type with a tracklist) listens for `persistentplayer:ended`
 * and decides for itself what, if anything, plays next.
 *
 * `item` shape: { id, src, title, subtitle, artwork, mediaType }.
 * `mediaType` names the backend that plays it: 'audio' (built in, an
 * <audio> element) or one another module registered, e.g. 'mixcloud'.
 *
 * A backend is registered with a factory, called once with the player's
 * embed container and a reporter, returning an object with:
 * - load(item): load (and start playing) an item.
 * - play(), pause(): control playback.
 * - isPaused(): whether playback is paused.
 * - unload(): stop and remove what it loaded.
 * - embedded: TRUE when the backend shows its own player in the embed
 *   container (the bar then hides its own play/pause and progress).
 * The reporter has play(), pause(), ended() and time(currentTime, duration)
 * for the backend to report what happens.
 */

(function (Drupal, once) {
  'use strict';

  const factories = {};
  const backends = {};
  let currentItem = null;
  let currentBackend = null;
  let elements = null;

  function dispatch(name, detail) {
    document.dispatchEvent(new CustomEvent(name, { detail: detail }));
  }

  function updatePlayPauseUi(playing) {
    elements.playPause.classList.toggle('is-playing', playing);
    elements.playPause.setAttribute('aria-label', playing ? Drupal.t('Pause') : Drupal.t('Play'));
  }

  function renderItem(item, backend) {
    elements.title.textContent = item.title || '';
    elements.subtitle.textContent = item.subtitle || '';
    if (item.artwork) {
      elements.artwork.src = item.artwork;
      elements.artwork.hidden = false;
    }
    else {
      elements.artwork.hidden = true;
      elements.artwork.removeAttribute('src');
    }
    elements.progress.value = 0;
    elements.root.classList.toggle('is-embedded', !!backend.embedded);
    elements.root.hidden = false;
  }

  /**
   * What a backend calls to report playback; ignored unless it is current.
   */
  function reporter(name) {
    const isCurrent = () => currentBackend === backends[name];
    return {
      play() {
        if (isCurrent()) {
          updatePlayPauseUi(true);
          dispatch('persistentplayer:play', { item: currentItem });
        }
      },
      pause() {
        if (isCurrent()) {
          updatePlayPauseUi(false);
          dispatch('persistentplayer:pause', { item: currentItem });
        }
      },
      ended() {
        if (isCurrent()) {
          updatePlayPauseUi(false);
          dispatch('persistentplayer:ended', { item: currentItem });
        }
      },
      time(currentTime, duration) {
        if (!isCurrent()) {
          return;
        }
        if (duration) {
          elements.progress.value = (currentTime / duration) * 100;
        }
        dispatch('persistentplayer:timeupdate', { item: currentItem, currentTime, duration });
      },
    };
  }

  function backend(name) {
    if (!backends[name] && factories[name]) {
      backends[name] = factories[name](elements.embed, reporter(name));
    }
    return backends[name] || null;
  }

  /**
   * The built-in backend: the <audio> element in the player.
   */
  function audioBackend(audio) {
    return (embed, report) => {
      audio.addEventListener('play', () => report.play());
      audio.addEventListener('pause', () => report.pause());
      audio.addEventListener('ended', () => report.ended());
      audio.addEventListener('timeupdate', () => report.time(audio.currentTime, audio.duration));
      // play() rejects when the browser won't play, e.g. before the visitor
      // has interacted with the page: show it as paused, so the play button
      // works. A load interrupted by the next one (AbortError) is expected.
      const start = () => {
        audio.play().catch((error) => {
          if (error.name !== 'AbortError') {
            report.pause();
          }
        });
      };
      return {
        embedded: false,
        load(item) {
          audio.src = item.src;
          start();
        },
        play() {
          start();
        },
        pause() {
          audio.pause();
        },
        isPaused() {
          return audio.paused || audio.ended;
        },
        unload() {
          audio.pause();
          audio.removeAttribute('src');
          audio.load();
        },
      };
    };
  }

  const PersistentPlayer = {
    /**
     * Plays a media item, or toggles play/pause if it's already loaded.
     *
     * @param {{id: string|number, src: string, title: string, subtitle: string, artwork: string, mediaType: string}} item
     *   The media item to play.
     */
    play(item) {
      if (!elements) {
        return;
      }
      if (currentItem && String(currentItem.id) === String(item.id)) {
        if (currentBackend.isPaused()) {
          currentBackend.play();
        }
        else {
          currentBackend.pause();
        }
        return;
      }
      const next = backend(item.mediaType || 'audio');
      if (!next) {
        Drupal.throwError(new Error(`No persistent player backend for "${item.mediaType}".`));
        return;
      }
      if (currentBackend) {
        currentBackend.unload();
      }
      currentItem = item;
      currentBackend = next;
      renderItem(item, next);
      next.load(item);
    },

    /**
     * Pauses playback without clearing the current item.
     */
    pause() {
      if (currentBackend) {
        currentBackend.pause();
      }
    },

    /**
     * Stops playback and hides the player.
     */
    stop() {
      if (!elements || !currentItem) {
        return;
      }
      const item = currentItem;
      currentBackend.unload();
      currentItem = null;
      currentBackend = null;
      elements.root.hidden = true;
      elements.root.classList.remove('is-embedded');
      elements.progress.value = 0;
      updatePlayPauseUi(false);
      dispatch('persistentplayer:stop', { item: item });
    },

    /**
     * @return {object|null}
     *   The currently loaded media item, or null if nothing is loaded.
     */
    getCurrent() {
      return currentItem;
    },

    /**
     * Registers a backend for a media type (see the file comment).
     *
     * @param {string} mediaType
     *   The item mediaType it plays.
     * @param {function(HTMLElement, object): object} factory
     *   Creates the backend from the embed container and a reporter.
     */
    registerBackend(mediaType, factory) {
      factories[mediaType] = factory;
    },
  };

  Drupal.persistentPlayer = PersistentPlayer;

  Drupal.behaviors.persistentPlayer = {
    attach(context) {
      once('persistent-player-init', '#persistent-player-audio', context).forEach((audioEl) => {
        elements = {
          root: document.getElementById('persistent-player'),
          playPause: document.querySelector('.persistent-player__play-pause'),
          stop: document.querySelector('.persistent-player__stop'),
          artwork: document.querySelector('.persistent-player__artwork'),
          title: document.querySelector('.persistent-player__title'),
          subtitle: document.querySelector('.persistent-player__subtitle'),
          progress: document.querySelector('.persistent-player__progress'),
          embed: document.querySelector('.persistent-player__embed'),
        };
        PersistentPlayer.registerBackend('audio', audioBackend(audioEl));

        elements.playPause.addEventListener('click', () => {
          if (currentItem) {
            PersistentPlayer.play(currentItem);
          }
        });
        elements.stop.addEventListener('click', () => PersistentPlayer.stop());
      });
    },
  };
})(Drupal, once);
