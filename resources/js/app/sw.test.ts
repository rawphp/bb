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

describe('sw', () => {
    const manifest = [{ url: 'assets/main-a.js', revision: null }];

    beforeEach(() => {
        vi.resetModules();
        Object.assign(self, { __WB_MANIFEST: manifest });
    });

    it('OFF-002 precaches the build plus the shell, and answers app navigations with the shell', async () => {
        await import('./sw');

        expect(workbox.cleanupOutdatedCaches).toHaveBeenCalled();
        expect(workbox.precacheAndRoute).toHaveBeenCalledWith([...manifest, shellEntry(manifest)]);
        expect(workbox.createHandlerBoundToURL).toHaveBeenCalledWith(SHELL_URL);
        expect(workbox.registerRoute).toHaveBeenCalledWith({ handler: `handler for ${SHELL_URL}`, options: { denylist: [SERVER_PATHS] } });
    });
});
