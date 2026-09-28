/**
 * @file
 * The persistent media player public API.
 *
 * Exposes `Drupal.persistentPlayer` with `play()`, `pause()`, `stop()`, and
 * `getCurrent()`. Other modules should not reach into the DOM directly -
 * depend on this API and the `persistentplayer:*` events it dispatches on
 * `document` instead:
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
 * `mediaType` is currently always 'audio'.
 */

(function (Drupal, once) {
  'use strict';

  let currentItem = null;
  let audio = null;
  let elements = null;

  function dispatch(name, detail) {
    document.dispatchEvent(new CustomEvent(name, { detail: detail }));
  }

  function updatePlayPauseUi() {
    elements.playPause.classList.toggle('is-playing', !audio.paused && !audio.ended);
    elements.playPause.setAttribute(
      'aria-label',
      !audio.paused && !audio.ended ? Drupal.t('Pause') : Drupal.t('Play'),
    );
  }

  function renderItem(item) {
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
    elements.root.hidden = false;
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
        if (audio.paused) {
          audio.play();
        }
        else {
          audio.pause();
        }
        return;
      }
      currentItem = item;
      renderItem(item);
      audio.src = item.src;
      audio.play();
    },

    /**
     * Pauses playback without clearing the current item.
     */
    pause() {
      if (audio) {
        audio.pause();
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
      audio.pause();
      audio.removeAttribute('src');
      audio.load();
      currentItem = null;
      elements.root.hidden = true;
      elements.progress.value = 0;
      dispatch('persistentplayer:stop', { item: item });
    },

    /**
     * @return {object|null}
     *   The currently loaded media item, or null if nothing is loaded.
     */
    getCurrent() {
      return currentItem;
    },
  };

  Drupal.persistentPlayer = PersistentPlayer;

  Drupal.behaviors.persistentPlayer = {
    attach(context) {
      once('persistent-player-init', '#persistent-player-audio', context).forEach((audioEl) => {
        audio = audioEl;
        elements = {
          root: document.getElementById('persistent-player'),
          playPause: document.querySelector('.persistent-player__play-pause'),
          stop: document.querySelector('.persistent-player__stop'),
          artwork: document.querySelector('.persistent-player__artwork'),
          title: document.querySelector('.persistent-player__title'),
          subtitle: document.querySelector('.persistent-player__subtitle'),
          progress: document.querySelector('.persistent-player__progress'),
        };

        elements.playPause.addEventListener('click', () => {
          if (currentItem) {
            PersistentPlayer.play(currentItem);
          }
        });
        elements.stop.addEventListener('click', () => PersistentPlayer.stop());

        audio.addEventListener('play', () => {
          updatePlayPauseUi();
          dispatch('persistentplayer:play', { item: currentItem });
        });
        audio.addEventListener('pause', () => {
          updatePlayPauseUi();
          dispatch('persistentplayer:pause', { item: currentItem });
        });
        audio.addEventListener('ended', () => {
          updatePlayPauseUi();
          dispatch('persistentplayer:ended', { item: currentItem });
        });
        audio.addEventListener('timeupdate', () => {
          if (audio.duration) {
            elements.progress.value = (audio.currentTime / audio.duration) * 100;
          }
          dispatch('persistentplayer:timeupdate', {
            item: currentItem,
            currentTime: audio.currentTime,
            duration: audio.duration,
          });
        });
      });
    },
  };
})(Drupal, once);
