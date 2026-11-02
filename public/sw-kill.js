// The service worker's kill switch. It is not registered by anything. To take the app's
// worker off every device (a rollback of the PWA, a broken worker), serve this file at
// /sw.js: docs/service-worker.md has the steps. Browsers check /sw.js for a new version on
// a navigation (a cold launch, a reload); only while updates.ts is deployed do they also
// check when the app returns to the foreground. This one installs at once, deletes every
// cache the worker made and unregisters itself. It reloads nothing: an open tab (mid-workout,
// with a sheet open) keeps what is on screen, and is now under this worker, which has no
// fetch handler, so everything it loads comes from the network. Its next load is plain. Unregistering
// also ends the device's Web Push subscription; the docs say what that means.
self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            for (const name of await caches.keys()) await caches.delete(name);
            await self.registration.unregister();
        })(),
    );
});
