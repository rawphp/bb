/// <reference lib="webworker" />
import { cacheNames } from 'workbox-core';
import { hash } from './shell';

declare const self: ServiceWorkerGlobalScope;

type ManifestEntry = string | { url: string; revision?: string | null };

// PWA-035: that tap puts the new version under every open tab, and its activation drops
// the old build from the precache. A tab still running the old build (held mid-workout,
// say) would then fail to load its next chunk. So installing a version first keeps a copy
// of the build files it replaces, and a request for a file the precache no longer has is
// answered from a copy, online or not.
//
// Each copy is its own cache, named bb-previous-build-<n>-<build>: <n> counts up from the
// newest copy (not the device clock, which can be set back), and <build> is a fingerprint
// of the files it holds. Only the newest KEPT_BUILDS stay: a tab held through
// two deploys still finds its files, and the copies never grow past two builds however many
// deploys a device sees. An install the browser retries finds its build already copied and
// changes nothing, so retries never push out a copy an open tab needs.
export const PREVIOUS_BUILD_PREFIX = 'bb-previous-build-';
export const KEPT_BUILDS = 2;

// Runs beside Workbox's own install step, which adds this version's files to the same
// precache, so it copies only the files this version's manifest does not list. A failed
// copy is logged and never fails the install: the update matters more than the copy.
export async function keepPreviousBuild(manifest: readonly ManifestEntry[]): Promise<void> {
    try {
        const current = new Set(manifest.map((entry) => new URL(typeof entry === 'string' ? entry : entry.url, self.location.href).href));
        const precache = await caches.open(cacheNames.precache);
        const replaced = (await precache.keys()).filter((request) => {
            const url = plainUrl(request);
            return isBuildFile(new URL(url), self.location.origin) && !current.has(url);
        });
        // Nothing replaced (a first install, or a rebuild of the same files): keep the copies earlier updates made.
        if (replaced.length === 0) return;

        // A retried install of the same version: the copy is already made.
        const build = hash(replaced.map(plainUrl).sort().join('\n'));
        const earlier = await previousBuilds();
        if (earlier.some((name) => name.endsWith(`-${build}`))) return;

        // Make the new copy before dropping old ones, so a failure halfway leaves files to serve.
        // A copy that fails halfway is deleted, so it never counts as one of the builds kept.
        const name = `${PREVIOUS_BUILD_PREFIX}${(earlier[0] ? order(earlier[0]) : 0) + 1}-${build}`;
        try {
            const copy = await caches.open(name);
            for (const request of replaced) {
                const response = await precache.match(request);
                if (response) await copy.put(new Request(plainUrl(request)), response);
            }
        } catch (error) {
            await caches.delete(name);
            throw error;
        }
        for (const old of (await previousBuilds()).slice(KEPT_BUILDS)) await caches.delete(old);
    } catch (error) {
        console.error('The previous build was not kept', error);
    }
}

// The copies, newest first.
async function previousBuilds(): Promise<string[]> {
    return (await caches.keys()).filter((name) => name.startsWith(PREVIOUS_BUILD_PREFIX)).sort((a, b) => order(b) - order(a));
}

function order(name: string): number {
    return Number(name.slice(PREVIOUS_BUILD_PREFIX.length).split('-')[0]) || 0;
}

// The URL a page asks for. Workbox keys a revisioned file as url?__WB_REVISION__=…; build
// files are keyed plain (vite.config.ts, dontCacheBustURLsMatching), but either matches.
function plainUrl(request: Request): string {
    const url = new URL(request.url);
    url.searchParams.delete('__WB_REVISION__');

    return url.href;
}

// The newest copy that has the file answers, then the network; what the network sends is never kept.
export async function fromPreviousBuild({ request }: { request: Request }): Promise<Response> {
    for (const name of await previousBuilds()) {
        const response = await (await caches.open(name)).match(request);
        if (response) return response;
    }

    // A static build file passed on to the web server, not a server call (CAP-004 is about those).
    // eslint-disable-next-line bb/no-business-rules
    return fetch(request);
}

export function isBuildFile(url: URL, origin: string): boolean {
    return url.origin === origin && url.pathname.startsWith('/build/');
}

