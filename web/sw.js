/**
 * @file
 * Removes the service worker of the old Gatsby site (gatsby-plugin-offline).
 *
 * Browsers that visited the Gatsby site still have its worker at /sw.js,
 * which serves the old site from its cache. When they check for an update,
 * they get this one instead: it deletes the caches, unregisters itself and
 * reloads open pages, which then come from Drupal.
 */

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.map((key) => caches.delete(key)));
    await self.registration.unregister();
    const windows = await self.clients.matchAll({ type: 'window' });
    windows.forEach((client) => client.navigate(client.url));
  })());
});
