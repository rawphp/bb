import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { computed } from 'vue';

// main.ts boots the app; only its PWA wiring is under test, so the rest is stubbed.
const updates = vi.hoisted(() => ({ registerServiceWorker: vi.fn() }));

vi.mock('./updates', () => ({
    registerServiceWorker: updates.registerServiceWorker,
    updateBanner: computed(() => true),
    applyUpdate: vi.fn(),
}));
vi.mock('./App.vue', () => ({ default: { render: () => null } }));
vi.mock('./router', () => ({ makeRouter: () => ({ install: () => undefined }) }));
vi.mock('./call', () => ({ keepSynced: vi.fn() }));
vi.mock('../design/appearance', () => ({ applyStoredAppearance: vi.fn() }));

async function boot() {
    vi.resetModules();
    await import('./main');
}

describe('main', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        document.body.innerHTML = '<div id="app"></div>';
    });

    afterEach(() => {
        vi.unstubAllEnvs();
        document.body.innerHTML = '';
    });

    it('PWA-004 a production build registers the service worker', async () => {
        vi.stubEnv('PROD', true);

        await boot();

        expect(updates.registerServiceWorker).toHaveBeenCalledTimes(1);
    });

    it('PWA-004 a dev build, which has no worker, registers none', async () => {
        vi.stubEnv('PROD', false);

        await boot();

        expect(updates.registerServiceWorker).not.toHaveBeenCalled();
    });

    it('PWA-035 mounts the update banner on its own root, over every screen', async () => {
        await boot();

        const banner = [...document.body.querySelectorAll('button')].find((button) => button.textContent?.trim() === 'Updated, tap to reload');
        expect(banner, 'the banner is in the page').toBeDefined();
        expect(document.getElementById('app')!.contains(banner!)).toBe(false);
    });
});
