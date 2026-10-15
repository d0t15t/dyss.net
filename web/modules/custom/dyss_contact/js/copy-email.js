/**
 * @file
 * Copies the contact address from the page title to the clipboard.
 */
((Drupal, once) => {
  Drupal.behaviors.dyssContactCopyEmail = {
    attach(context) {
      // Needs the clipboard API (secure contexts only): without it the button
      // stays hidden and the address can still be selected by hand.
      if (!navigator.clipboard) {
        return;
      }
      once('dyss-contact-copy', '.dyss-contact-email__copy', context).forEach((button) => {
        const label = button.querySelector('.dyss-contact-email__label');
        const address = button.parentElement.firstChild.textContent.trim();
        const idle = label.textContent;
        let timer;
        button.hidden = false;
        button.title = idle;
        button.addEventListener('click', async () => {
          let copied = true;
          try {
            await navigator.clipboard.writeText(address);
          }
          catch (e) {
            copied = false;
          }
          label.textContent = copied ? Drupal.t('Copied') : Drupal.t('Copy failed');
          button.classList.add('is-copied');
          Drupal.announce(label.textContent);
          clearTimeout(timer);
          timer = setTimeout(() => {
            label.textContent = idle;
            button.classList.remove('is-copied');
          }, 2000);
        });
      });
    },
  };
})(Drupal, once);
