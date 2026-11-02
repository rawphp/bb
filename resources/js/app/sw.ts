/// <reference lib="webworker" />
import { cacheNames } from 'workbox-core';
import { cleanupOutdatedCaches, createHandlerBoundToURL, precacheAndRoute } from 'workbox-precaching';
import { NavigationRoute, registerRoute } from 'workbox-routing';
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
// answered from a copy, online or not.
//
// Each install's copy is its own cache, named by when it was made, and only the newest
// KEPT_BUILDS stay: a tab held through two deploys still finds its files, and the copies
// never grow past two builds however many deploys a device sees.
export const PREVIOUS_BUILD_PREFIX = 'bb-previous-build-';
export const KEPT_BUILDS = 2;

self.addEventListener('install', (event) => event.waitUntil(keepPreviousBuild()));

// Runs beside Workbox's own install step, which adds this version's files to the same
// precache, so it copies only the files this version's manifest does not list. A failed
// copy is logged and never fails the install: the update matters more than the copy.
async function keepPreviousBuild(): Promise<void> {
    try {
        const current = new Set(manifest.map((entry) => new URL(typeof entry === 'string' ? entry : entry.url, self.location.href).href));
        const precache = await caches.open(cacheNames.precache);
        const replaced = (await precache.keys()).filter((request) => {
            const url = plainUrl(request);
            return isBuildFile(new URL(url)) && !current.has(url);
        });
        // Nothing replaced (a first install, or a rebuild of the same files): keep the copies earlier updates made.
        if (replaced.length === 0) return;

        // Make the new copy before dropping old ones, so a failure halfway leaves files to serve.
        const copy = await caches.open(`${PREVIOUS_BUILD_PREFIX}${Date.now()}`);
        for (const request of replaced) {
            const response = await precache.match(request);
            if (response) await copy.put(new Request(plainUrl(request)), response);
        }
        for (const name of (await previousBuilds()).slice(KEPT_BUILDS)) await caches.delete(name);
    } catch (error) {
        console.error('The previous build was not kept', error);
    }
}

// The copies, newest first.
async function previousBuilds(): Promise<string[]> {
    const stamp = (name: string) => Number(name.slice(PREVIOUS_BUILD_PREFIX.length));

    return (await caches.keys()).filter((name) => name.startsWith(PREVIOUS_BUILD_PREFIX)).sort((a, b) => stamp(b) - stamp(a));
}

// The URL a page asks for. Workbox keys a revisioned file as url?__WB_REVISION__=…; build
// files are keyed plain (vite.config.ts, dontCacheBustURLsMatching), but either matches.
function plainUrl(request: Request): string {
    const url = new URL(request.url);
    url.searchParams.delete('__WB_REVISION__');

    return url.href;
}

// The newest copy that has the file answers, then the network; what the network sends is never kept.
async function fromPreviousBuild({ request }: { request: Request }): Promise<Response> {
    for (const name of await previousBuilds()) {
        const response = await (await caches.open(name)).match(request);
        if (response) return response;
    }

    // A static build file passed on to the web server, not a server call (CAP-004 is about those).
    // eslint-disable-next-line bb/no-business-rules
    return fetch(request);
}

function isBuildFile(url: URL): boolean {
    return url.origin === self.location.origin && url.pathname.startsWith('/build/');
}

cleanupOutdatedCaches();
precacheAndRoute([...manifest, shellEntry(manifest)]);
registerRoute(new NavigationRoute(createHandlerBoundToURL(SHELL_URL), { denylist: [SERVER_PATHS] }));
// After the precache's own route, so it sees only build files the current version does not have.
registerRoute(({ url }) => isBuildFile(url), { handle: fromPreviousBuild });

listenForPush(self);
