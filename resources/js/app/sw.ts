/// <reference lib="webworker" />
import { cleanupOutdatedCaches, createHandlerBoundToURL, precacheAndRoute } from 'workbox-precaching';
import { NavigationRoute, registerRoute } from 'workbox-routing';
import { SERVER_PATHS, SHELL_URL, shellEntry } from './shell';

declare const self: ServiceWorkerGlobalScope;

// OFF-002: precache the build (JS, CSS, fonts, icons) and the HTML shell, and
// answer every app navigation with the cached shell, online or not.
// Reads are cached in IndexedDB by call.ts (OFF-003); SHELL_PWA registers this worker.
const manifest = self.__WB_MANIFEST;

// PWA-035: a new version waits until the tab where the update banner was tapped asks for it.
self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') void self.skipWaiting();
});

cleanupOutdatedCaches();
precacheAndRoute([...manifest, shellEntry(manifest)]);
registerRoute(new NavigationRoute(createHandlerBoundToURL(SHELL_URL), { denylist: [SERVER_PATHS] }));
