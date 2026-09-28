/**
 * @file
 * Wires a DJ Mix's tracklist play buttons to the persistent player, and
 * implements "auto-advance to the next track" - a DJ Mix specific concept
 * the persistent_player module deliberately knows nothing about.
 */

(function (Drupal, once) {
  'use strict';

  // The playlist currently backing auto-advance: an ordered array of items
  // built from the tracklist the visitor last clicked play in, plus the
  // index of the track that's playing (or just finished). Kept outside any
  // single node's DOM so it still works after a pjax navigation away from
  // that mix's page - the persistent player has no idea what a "mix" is, so
  // this module has to remember it instead.
  let activePlaylist = null;
  let activeIndex = -1;

  function buttonToItem(button) {
    return {
      id: button.dataset.mediaId,
      src: button.dataset.trackSrc,
      title: button.dataset.trackTitle,
      subtitle: button.dataset.trackArtist,
      artwork: button.dataset.trackCover || undefined,
      mediaType: 'audio',
    };
  }

  function updateButtonStates() {
    const current = Drupal.persistentPlayer.getCurrent();
    document.querySelectorAll('.track-row__play-btn').forEach((button) => {
      button.classList.toggle('is-playing', !!current && String(current.id) === button.dataset.mediaId);
    });
  }

  Drupal.behaviors.djMixesTrackButtons = {
    attach(context) {
      once('dj-mix-play-btn', '.track-row__play-btn', context).forEach((button) => {
        button.addEventListener('click', () => {
          const tracklist = button.closest('.field--name-field-tracks');
          const buttons = tracklist
            ? Array.from(tracklist.querySelectorAll('.track-row__play-btn'))
            : [button];

          activePlaylist = buttons.map(buttonToItem);
          activeIndex = buttons.indexOf(button);

          Drupal.persistentPlayer.play(activePlaylist[activeIndex]);
        });
      });

      once('dj-mixes-player-events', 'body', context).forEach(() => {
        document.addEventListener('persistentplayer:play', updateButtonStates);
        document.addEventListener('persistentplayer:pause', updateButtonStates);
        document.addEventListener('persistentplayer:stop', updateButtonStates);

        document.addEventListener('persistentplayer:ended', (event) => {
          if (!activePlaylist || !event.detail.item) {
            return;
          }
          if (String(activePlaylist[activeIndex]?.id) !== String(event.detail.item.id)) {
            // Something else played in between; this playlist is stale.
            return;
          }
          if (activeIndex + 1 < activePlaylist.length) {
            activeIndex += 1;
            Drupal.persistentPlayer.play(activePlaylist[activeIndex]);
          }
          else {
            activePlaylist = null;
            activeIndex = -1;
          }
        });
      });

      // A pjax navigation may have just rendered this mix's tracklist again;
      // reflect whatever is already playing.
      updateButtonStates();
    },
  };
})(Drupal, once);
