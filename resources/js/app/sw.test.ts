import { beforeEach, describe, expect, it, vi } from 'vitest';
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
    async match(request: Request, options: { cacheName: string }) {
        return stores.get(options.cacheName)?.match(request);
    },
};

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

function openTabs(count: number) {
    Object.defineProperty(self, 'clients', { value: { matchAll: vi.fn(async () => Array.from({ length: count }, () => ({}))) }, configurable: true });
}

describe('sw', () => {
    // This version's files, as the build injects them.
    const manifest = [
        { url: 'build/assets/main-new.js', revision: null },
        { url: 'icons/icon-192.png', revision: 'a1' },
    ];

    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        stores.clear();
        vi.stubGlobal('caches', fakeCaches);
        // Workbox's strategies check for the worker's own event types, which jsdom lacks.
        vi.stubGlobal('FetchEvent', class extends Event {});
        vi.stubGlobal('ExtendableEvent', class extends Event {});
        Object.assign(self, { __WB_MANIFEST: manifest });
        openTabs(0);
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

    it('PWA-035 installing a new version keeps a copy of the build it replaces', async () => {
        const { PREVIOUS_BUILD } = await import('./sw');
        await cache(PREVIOUS_BUILD, ['/build/assets/older-a.js']);
        // Workbox's own install step may already have added this version's files.
        await cache('precache', ['/build/assets/main-old.js', '/build/assets/main-old.css', '/build/assets/main-new.js', '/icons/icon-192.png', '/?__WB_REVISION__=1']);

        await install();

        expect([...stores.get(PREVIOUS_BUILD)!.entries.keys()].sort()).toEqual([at('/build/assets/main-old.css'), at('/build/assets/main-old.js')]);
        expect(await (await fakeCaches.match(new Request(at('/build/assets/main-old.js')), { cacheName: PREVIOUS_BUILD }))!.text()).toBe(
            'precache /build/assets/main-old.js',
        );
    });

    it('PWA-035 with a tab open, an install adds to the earlier copy, so a tab held through two deploys keeps its files', async () => {
        const { PREVIOUS_BUILD } = await import('./sw');
        openTabs(1);
        // Tab A runs v1 (in the copy); v2 is the precache being replaced by this version.
        await cache(PREVIOUS_BUILD, ['/build/assets/main-v1.js']);
        await cache('precache', ['/build/assets/main-v2.js', '/build/assets/main-new.js']);

        await install();

        expect([...stores.get(PREVIOUS_BUILD)!.entries.keys()].sort()).toEqual([at('/build/assets/main-v1.js'), at('/build/assets/main-v2.js')]);
        expect(self.clients.matchAll).toHaveBeenCalledWith({ type: 'window', includeUncontrolled: true });
    });

    it('PWA-035 a first install, with no build to replace, keeps the copy an earlier update made', async () => {
        const { PREVIOUS_BUILD } = await import('./sw');
        await cache(PREVIOUS_BUILD, ['/build/assets/older-a.js']);
        await cache('precache', ['/build/assets/main-new.js']);

        await install();

        expect([...stores.get(PREVIOUS_BUILD)!.entries.keys()]).toEqual([at('/build/assets/older-a.js')]);
    });

    it('PWA-035 a copy that fails halfway never fails the install, and leaves the earlier copy to serve', async () => {
        const { PREVIOUS_BUILD } = await import('./sw');
        const error = vi.spyOn(console, 'error').mockImplementation(() => undefined);
        await cache(PREVIOUS_BUILD, ['/build/assets/older-a.js']);
        await cache('precache', ['/build/assets/main-old.js']);
        vi.spyOn(stores.get(PREVIOUS_BUILD)!, 'put').mockRejectedValue(new DOMException('full', 'QuotaExceededError'));

        await expect(install()).resolves.toBeUndefined();

        expect(error).toHaveBeenCalledWith('The previous build was not kept', expect.any(DOMException));
        expect([...stores.get(PREVIOUS_BUILD)!.entries.keys()]).toEqual([at('/build/assets/older-a.js')]);
        error.mockRestore();
    });

    it('PWA-035 a tab still on the old build gets its files from that copy, online or not', async () => {
        const { PREVIOUS_BUILD } = await import('./sw');
        await cache(PREVIOUS_BUILD, ['/build/assets/main-old.js']);
        const network = vi.fn(async () => new Response('network'));
        vi.stubGlobal('fetch', network);
        const route = buildRoute();
        const [matches] = route;

        expect(matches({ url: new URL(at('/build/assets/main-old.js')) })).toBe(true);
        expect(matches({ url: new URL(at('/icons/icon-192.png')) })).toBe(false);
        expect(matches({ url: new URL('https://elsewhere.test/build/assets/main-old.js') })).toBe(false);

        expect(await (await answer(route, '/build/assets/main-old.js')).text()).toBe(`${PREVIOUS_BUILD} /build/assets/main-old.js`);
        expect(network).not.toHaveBeenCalled();

        expect(await (await answer(route, '/build/assets/unknown.js')).text()).toBe('network');
        expect(network).toHaveBeenCalledTimes(1);
        // The copy holds only the replaced build; a network answer is not added to it.
        expect([...stores.get(PREVIOUS_BUILD)!.entries.keys()]).toEqual([at('/build/assets/main-old.js')]);
    });

    it('PWA-035 the route for old build files comes after the precache and the shell, so it never shadows them', async () => {
        await import('./sw');

        const precached = workbox.precacheAndRoute.mock.invocationCallOrder[0]!;
        const [navigation, build] = workbox.registerRoute.mock.invocationCallOrder;

        expect(precached).toBeLessThan(navigation!);
        expect(navigation).toBeLessThan(build!);
    });
});

