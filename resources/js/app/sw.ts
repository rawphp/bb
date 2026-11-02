/// <reference lib="webworker" />
import { cacheNames } from 'workbox-core';
import { cleanupOutdatedCaches, createHandlerBoundToURL, precacheAndRoute } from 'workbox-precaching';
import { NavigationRoute, registerRoute } from 'workbox-routing';
import { CacheFirst } from 'workbox-strategies';
import { listenForPush } from '../features/notifications/serviceWorker';
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

// PWA-035: that tap puts the new version under every open tab, and its activation drops
// the old build from the precache. A tab still running the old build (held mid-workout,
// say) would then fail to load its next chunk. So installing a version first keeps a copy
// of the build files it replaces, and a request for a file the precache no longer has is
// answered from that copy, online or not.
export const PREVIOUS_BUILD = 'bb-previous-build';

self.addEventListener('install', (event) => event.waitUntil(keepPreviousBuild()));

// Runs beside Workbox's own install step, which adds this version's files to the same
// precache, so it copies only the files this version's manifest does not list. A failed
// copy is logged and never fails the install: the update matters more than the copy.
async function keepPreviousBuild(): Promise<void> {
    try {
        const current = new Set(manifest.map((entry) => new URL(typeof entry === 'string' ? entry : entry.url, self.location.href).href));
        const precache = await caches.open(cacheNames.precache);
        const replaced = (await precache.keys()).filter((request) => isBuildFile(new URL(request.url)) && !current.has(request.url));
        // Nothing replaced (a first install, or a rebuild of the same files): keep the copy an earlier update made.
        if (replaced.length === 0) return;

        // Add the new copy before dropping the old one, so a failure halfway leaves files to serve.
        const previous = await caches.open(PREVIOUS_BUILD);
        for (const request of replaced) {
            const response = await precache.match(request);
            if (response) await previous.put(request, response);
        }
        const kept = new Set(replaced.map((request) => request.url));
        for (const request of await previous.keys()) {
            if (!kept.has(request.url)) await previous.delete(request);
        }
    } catch (error) {
        console.error('The previous build was not kept', error);
    }
}

function isBuildFile(url: URL): boolean {
    return url.origin === self.location.origin && url.pathname.startsWith('/build/');
}

cleanupOutdatedCaches();
precacheAndRoute([...manifest, shellEntry(manifest)]);
registerRoute(new NavigationRoute(createHandlerBoundToURL(SHELL_URL), { denylist: [SERVER_PATHS] }));
// After the precache's own route, so it sees only build files the current version does not have.
// The copy answers first, then the network; what the network sends is never added to the copy.
registerRoute(({ url }) => isBuildFile(url), new CacheFirst({ cacheName: PREVIOUS_BUILD, plugins: [{ cacheWillUpdate: async () => null }] }));

listenForPush(self);
