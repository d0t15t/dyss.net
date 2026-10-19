/**
 * @file
 * Scrolls a sideways-scrolling DJ mix pager to the mix being viewed.
 *
 * CSS can't set a starting scroll position. When the cards are laid out
 * without overflow (e.g. as a grid) this does nothing.
 */
((Drupal, once) => {
  Drupal.behaviors.djMixPager = {
    attach(context) {
      once('dj-mix-pager', '.dj-mix-pager__cards', context).forEach((list) => {
        const current = list.querySelector('.is-current');
        if (current && list.scrollWidth > list.clientWidth) {
          list.scrollLeft = current.offsetLeft - (list.clientWidth - current.offsetWidth) / 2;
        }
      });
    },
  };
})(Drupal, once);
