/**
 * @file
 * Plays Mixcloud shows in the persistent player (its "mixcloud" backend).
 *
 * Mixcloud audio may only be played through Mixcloud's own player, so the
 * backend embeds Mixcloud's mini widget in the persistent player bar and
 * follows it through the Widget API. As the bar survives same-site
 * navigation, the show keeps playing across pages. Items: mediaType
 * 'mixcloud', src the show's Mixcloud URL.
 */
((Drupal) => {
  Drupal.persistentPlayer.registerBackend('mixcloud', (embed, report) => {
    let widget = null;
    let paused = true;

    function feed(url) {
      return new URL(url, 'https://www.mixcloud.com').pathname;
    }

    function unload() {
      embed.replaceChildren();
      widget = null;
      paused = true;
    }

    return {
      embedded: true,

      load(item) {
        unload();
        const iframe = document.createElement('iframe');
        const query = new URLSearchParams({
          feed: feed(item.src),
          hide_cover: '1',
          mini: '1',
          light: '1',
          autoplay: '1',
        });
        iframe.src = `https://player-widget.mixcloud.com/widget/iframe/?${query}`;
        iframe.title = item.title || Drupal.t('Mixcloud player');
        iframe.allow = 'autoplay; encrypted-media';
        embed.replaceChildren(iframe);

        // One Widget API handle per iframe: a second one takes over its
        // messages. Others can reuse this one as iframe.mixcloudWidget.
        const current = window.Mixcloud.PlayerWidget(iframe);
        iframe.mixcloudWidget = current;
        widget = current;
        current.ready.then(() => {
          if (widget !== current) {
            return;
          }
          current.events.play.on(() => {
            paused = false;
            report.play();
          });
          current.events.pause.on(() => {
            paused = true;
            report.pause();
          });
          current.events.ended.on(() => {
            paused = true;
            report.ended();
          });
          current.events.progress.on((position, duration) => report.time(position, duration));
        });
      },

      play() {
        widget?.ready.then(() => widget?.play());
      },

      pause() {
        widget?.ready.then(() => widget?.pause());
      },

      isPaused() {
        return paused;
      },

      unload,
    };
  });
})(Drupal);
