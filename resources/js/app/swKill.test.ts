import { readFileSync } from 'node:fs';
import { describe, expect, it, vi } from 'vitest';

// public/sw-kill.js runs as plain JavaScript at /sw.js, with no build; docs/service-worker.md.
const source = readFileSync('public/sw-kill.js', 'utf8');

function run() {
    const listeners: Record<string, (event: { waitUntil(wait: Promise<unknown>): void }) => void> = {};
    const names = ['workbox-precache-v2-https://bb.test/', 'bb-previous-build-1-abc'];
    const client = { url: 'https://bb.test/today', navigate: vi.fn(async () => undefined) };
    const self = {
        addEventListener: (type: string, listener: (event: { waitUntil(wait: Promise<unknown>): void }) => void) => (listeners[type] = listener),
        skipWaiting: vi.fn(async () => undefined),
        registration: { unregister: vi.fn(async () => true) },
        clients: { matchAll: vi.fn(async () => [client]) },
    };
    const caches = { keys: vi.fn(async () => [...names]), delete: vi.fn(async (name: string) => names.splice(names.indexOf(name), 1).length > 0) };
    new Function('self', 'caches', source)(self, caches);

    async function dispatch(type: string) {
        const waits: Promise<unknown>[] = [];
        listeners[type]?.({ waitUntil: (wait) => waits.push(wait) });
        await Promise.all(waits);
    }

    return { self, caches, client, names, dispatch };
}

describe('sw-kill', () => {
    it('PWA-035 takes over at once on install', async () => {
        const { self, dispatch } = run();

        await dispatch('install');

        expect(self.skipWaiting).toHaveBeenCalled();
    });

    it('PWA-035 on activate deletes every cache and unregisters, and reloads no tab, so a workout or open sheet is never cut off', async () => {
        const { self, client, names, dispatch } = run();

        await dispatch('activate');

        expect(names).toEqual([]);
        expect(self.registration.unregister).toHaveBeenCalled();
        expect(client.navigate).not.toHaveBeenCalled();
    });

    it('PWA-035 has no fetch handler, so a tab it is left controlling loads from the network', () => {
        expect(source).not.toMatch(/addEventListener\(\s*['"]fetch['"]/);
    });
});
