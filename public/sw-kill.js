// The service worker's kill switch. It is not registered by anything. To take the app's
// worker off every device (a rollback of the PWA, a broken worker), serve this file at
// /sw.js: docs/service-worker.md has the steps. Browsers check /sw.js for a new version on
// a navigation (a cold launch, a reload); only while updates.ts is deployed do they also
// check when the app returns to the foreground. This one installs at once, deletes every
// cache the worker made, unregisters itself, and reloads the tabs it controlled from the
// network. A reloaded page is not controlled, so it is not reloaded again. Unregistering
// also ends the device's Web Push subscription; the docs say what that means.
self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            for (const name of await caches.keys()) await caches.delete(name);
            await self.registration.unregister();
            for (const client of await self.clients.matchAll({ type: 'window' })) await client.navigate(client.url);
        })(),
    );
});
