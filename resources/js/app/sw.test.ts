import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { SERVER_PATHS, SHELL_URL, shellEntry } from './shell';

const workbox = vi.hoisted(() => ({
    cleanupOutdatedCaches: vi.fn(),
    precacheAndRoute: vi.fn(),
    createHandlerBoundToURL: vi.fn((url: string) => `handler for ${url}`),
    registerRoute: vi.fn(),
    NavigationRoute: vi.fn(function (this: object, handler: unknown, options: unknown) {
        Object.assign(this, { handler, options });
    }),
}));

vi.mock('workbox-precaching', () => workbox);
vi.mock('workbox-routing', () => workbox);
vi.mock('workbox-core', () => ({ cacheNames: { precache: 'precache' } }));

// CacheStorage as the worker sees it, keyed by URL.
class FakeCache {
    entries = new Map<string, Response>();
    async keys() {
        return [...this.entries.keys()].map((url) => new Request(url));
    }
    async match(request: Request | string) {
        return this.entries.get(typeof request === 'string' ? request : request.url);
    }
    async put(request: Request, response: Response) {
        this.entries.set(request.url, response);
    }
    async delete(request: Request) {
        return this.entries.delete(request.url);
    }
}

const stores = new Map<string, FakeCache>();
const fakeCaches = {
    async open(name: string) {
        if (!stores.has(name)) stores.set(name, new FakeCache());
        return stores.get(name)!;
    },
    async delete(name: string) {
        return stores.delete(name);
    },
    async keys() {
        return [...stores.keys()];
    },
    async match(request: Request, options: { cacheName: string }) {
        return stores.get(options.cacheName)?.match(request);
    },
};

const PREFIX = 'bb-previous-build-';
const stamp = (name: string) => Number(name.slice(PREFIX.length).split('-')[0]);
const at = (path: string) => new URL(path, self.location.origin).href;

async function cache(name: string, paths: string[]) {
    const store = await fakeCaches.open(name);
    for (const path of paths) await store.put(new Request(at(path)), new Response(`${name} ${path}`));
}

async function install() {
    const waits: Promise<unknown>[] = [];
    self.dispatchEvent(Object.assign(new Event('install'), { waitUntil: (wait: Promise<unknown>) => waits.push(wait) }));
    await Promise.all(waits);
}

type BuildRoute = [(options: { url: URL }) => boolean, { handle(options: { request: Request; event: ExtendableEvent }): Promise<Response> }];

// What the route answers for a request, as Workbox's router calls it, once the work it leaves running is done.
async function answer([, strategy]: BuildRoute, path: string): Promise<Response> {
    const waits: Promise<unknown>[] = [];
    const event = Object.assign(new ExtendableEvent('fetch'), { waitUntil: (wait: Promise<unknown>) => waits.push(wait) });
    const response = await strategy.handle({ request: new Request(at(path)), event });
    await Promise.all(waits);
    return response;
}

// The route registered after the navigation route: build files the precache no longer has.
function buildRoute(): BuildRoute {
    const route = workbox.registerRoute.mock.calls.map((call) => call as unknown[]).find((call) => typeof call[0] === 'function');
    expect(route, 'a route for old build files').toBeDefined();
    return route as unknown as BuildRoute;
}

describe('sw', () => {
    afterEach(() => {
        for (const [type, listener] of added.splice(0)) self.removeEventListener(type, listener);
        vi.restoreAllMocks();
    });

    // This version's files, as the build injects them.
    const manifest = [
        { url: 'build/assets/main-new.js', revision: null },
        { url: 'icons/icon-192.png', revision: 'a1' },
    ];

    // Each test imports the worker afresh; the listeners an earlier import added must not run again.
    const added: [string, EventListenerOrEventListenerObject][] = [];
    const addEventListener = self.addEventListener.bind(self);

    beforeEach(() => {
        vi.spyOn(self, 'addEventListener').mockImplementation((type: string, listener: EventListenerOrEventListenerObject) => {
            added.push([type, listener]);
            addEventListener(type, listener);
        });
        vi.resetModules();
        vi.clearAllMocks();
        stores.clear();
        vi.stubGlobal('caches', fakeCaches);
        // Workbox's strategies check for the worker's own event types, which jsdom lacks.
        vi.stubGlobal('FetchEvent', class extends Event {});
        vi.stubGlobal('ExtendableEvent', class extends Event {});
        Object.assign(self, { __WB_MANIFEST: manifest });
    });

    it('OFF-002 precaches the build plus the shell, and answers app navigations with the shell', async () => {
        await import('./sw');

        expect(workbox.cleanupOutdatedCaches).toHaveBeenCalled();
        expect(workbox.precacheAndRoute).toHaveBeenCalledWith([...manifest, shellEntry(manifest)]);
        expect(workbox.createHandlerBoundToURL).toHaveBeenCalledWith(SHELL_URL);
        expect(workbox.registerRoute).toHaveBeenCalledWith({ handler: `handler for ${SHELL_URL}`, options: { denylist: [SERVER_PATHS] } });
    });

    it('PWA-035 waits until a tapped update banner asks it to take over', async () => {
        const skipWaiting = vi.fn(async () => undefined);
        Object.assign(self, { skipWaiting });

        await import('./sw');
        self.dispatchEvent(new MessageEvent('message', { data: { type: 'OTHER' } }));
        expect(skipWaiting).not.toHaveBeenCalled();

        self.dispatchEvent(new MessageEvent('message', { data: { type: 'SKIP_WAITING' } }));
        expect(skipWaiting).toHaveBeenCalled();
    });

    // The copies on the device, oldest first, as name: [paths].
    function copies(): Record<string, string[]> {
        return Object.fromEntries(
            [...stores.entries()]
                .filter(([name]) => name.startsWith(PREFIX))
                .sort(([a], [b]) => stamp(a) - stamp(b))
                .map(([name, store]) => [name, [...store.entries.keys()].map((url) => new URL(url).pathname + new URL(url).search).sort()]),
        );
    }

    it('PWA-035 installing a new version keeps a copy of the build it replaces', async () => {
        await import('./sw');
        // Workbox's own install step may already have added this version's files.
        await cache('precache', ['/build/assets/main-old.js', '/build/assets/main-old.css', '/build/assets/main-new.js', '/icons/icon-192.png', '/?__WB_REVISION__=1']);

        await install();

        expect(Object.values(copies())).toEqual([['/build/assets/main-old.css', '/build/assets/main-old.js']]);
        const [name] = Object.keys(copies());
        expect(await (await fakeCaches.match(new Request(at('/build/assets/main-old.js')), { cacheName: name! }))!.text()).toBe('precache /build/assets/main-old.js');
    });

    it('PWA-035 keeps the newest two builds, so a tab held through two deploys keeps its files and the copies stay bounded', async () => {
        await import('./sw');
        const { KEPT_BUILDS } = await import('./previousBuild');
        expect(KEPT_BUILDS).toBe(2);

        for (const old of ['v1', 'v2', 'v3']) {
            stores.delete('precache');
            await cache('precache', [`/build/assets/main-${old}.js`, '/build/assets/main-new.js']);
            await install();
        }

        expect(Object.values(copies())).toEqual([['/build/assets/main-v2.js'], ['/build/assets/main-v3.js']]);
        vi.restoreAllMocks();
    });

    it('PWA-035 a revisioned build file is still recognised by its plain URL, and copied under it', async () => {
        Object.assign(self, { __WB_MANIFEST: [{ url: 'build/assets/main-new.js', revision: 'n1' }] });
        await import('./sw');
        await cache('precache', ['/build/assets/main-old.js?__WB_REVISION__=o1', '/build/assets/main-new.js?__WB_REVISION__=n1']);

        await install();

        expect(Object.values(copies())).toEqual([['/build/assets/main-old.js']]);
    });

    it('PWA-035 copies are ordered by a count, not the device clock, so a clock set back never drops the fresh copy', async () => {
        await import('./sw');
        await cache(`${PREFIX}7-aaaa`, ['/build/assets/main-v1.js']);
        await cache('precache', ['/build/assets/main-v2.js', '/build/assets/main-new.js']);
        vi.spyOn(Date, 'now').mockReturnValue(0);

        await install();

        expect(Object.keys(copies())[1]).toMatch(new RegExp(`^${PREFIX}8-`));
        expect(Object.values(copies())).toEqual([['/build/assets/main-v1.js'], ['/build/assets/main-v2.js']]);
        vi.restoreAllMocks();
    });

    it('PWA-035 a first install, with no build to replace, keeps the copies earlier updates made', async () => {
        await import('./sw');
        await cache(`${PREFIX}1`, ['/build/assets/older-a.js']);
        await cache('precache', ['/build/assets/main-new.js']);

        await install();

        expect(copies()).toEqual({ [`${PREFIX}1`]: ['/build/assets/older-a.js'] });
    });

    it('PWA-035 a copy that fails halfway never fails the install, and leaves the earlier copies to serve', async () => {
        await import('./sw');
        const error = vi.spyOn(console, 'error').mockImplementation(() => undefined);
        await cache(`${PREFIX}1`, ['/build/assets/older-a.js']);
        await cache(`${PREFIX}2`, ['/build/assets/older-b.js']);
        await cache('precache', ['/build/assets/main-old.js']);
        vi.spyOn(FakeCache.prototype, 'put').mockRejectedValue(new DOMException('full', 'QuotaExceededError'));

        await expect(install()).resolves.toBeUndefined();

        expect(error).toHaveBeenCalledWith('The previous build was not kept', expect.any(DOMException));
        // The half-made copy is gone, so it never counts as one of the two builds kept.
        expect(copies()).toEqual({ [`${PREFIX}1`]: ['/build/assets/older-a.js'], [`${PREFIX}2`]: ['/build/assets/older-b.js'] });
        vi.restoreAllMocks();
    });

    it('PWA-035 after a copy fails halfway, the next install still keeps the newest two whole builds', async () => {
        await import('./sw');
        const error = vi.spyOn(console, 'error').mockImplementation(() => undefined);
        await cache(`${PREFIX}1`, ['/build/assets/main-v1.js']);
        await cache('precache', ['/build/assets/main-v2.js', '/build/assets/main-new.js']);
        const put = vi.spyOn(FakeCache.prototype, 'put').mockRejectedValueOnce(new DOMException('full', 'QuotaExceededError'));
        await install();
        put.mockRestore();

        await install();

        expect(Object.values(copies())).toEqual([['/build/assets/main-v1.js'], ['/build/assets/main-v2.js']]);
        error.mockRestore();
    });

    it('PWA-035 an install the browser retries copies nothing again, so the copy an older open tab needs stays', async () => {
        await import('./sw');
        // Tab A runs v1 from its copy; this version replaces v2.
        await cache(`${PREFIX}1`, ['/build/assets/main-v1.js']);
        await cache('precache', ['/build/assets/main-v2.js', '/build/assets/main-new.js']);

        for (let attempt = 0; attempt < 3; attempt++) await install();

        expect(Object.values(copies())).toEqual([['/build/assets/main-v1.js'], ['/build/assets/main-v2.js']]);
        vi.restoreAllMocks();
    });

    it('PWA-035 a tab still on an old build gets its files from the newest copy that has them, online or not', async () => {
        await import('./sw');
        await cache(`${PREFIX}1`, ['/build/assets/main-v1.js', '/build/assets/shared.js']);
        await cache(`${PREFIX}2`, ['/build/assets/main-v2.js', '/build/assets/shared.js']);
        const network = vi.fn(async () => new Response('network'));
        vi.stubGlobal('fetch', network);
        const route = buildRoute();
        const [matches] = route;

        expect(matches({ url: new URL(at('/build/assets/main-v1.js')) })).toBe(true);
        expect(matches({ url: new URL(at('/icons/icon-192.png')) })).toBe(false);
        expect(matches({ url: new URL('https://elsewhere.test/build/assets/main-v1.js') })).toBe(false);

        expect(await (await answer(route, '/build/assets/main-v1.js')).text()).toBe(`${PREFIX}1 /build/assets/main-v1.js`);
        expect(await (await answer(route, '/build/assets/shared.js')).text()).toBe(`${PREFIX}2 /build/assets/shared.js`);
        expect(network).not.toHaveBeenCalled();

        expect(await (await answer(route, '/build/assets/unknown.js')).text()).toBe('network');
        expect(network).toHaveBeenCalledTimes(1);
        // A network answer is not added to any copy.
        expect(Object.values(copies()).flat()).not.toContain('/build/assets/unknown.js');
    });

    it('PWA-035 the route for old build files comes after the precache and the shell, so it never shadows them', async () => {
        await import('./sw');

        const precached = workbox.precacheAndRoute.mock.invocationCallOrder[0]!;
        const [navigation, build] = workbox.registerRoute.mock.invocationCallOrder;

        expect(precached).toBeLessThan(navigation!);
        expect(navigation).toBeLessThan(build!);
    });
});

