import { afterEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory } from 'vue-router';
import { devRoutesEnabled, featureRoutes, makeRouter, routesFor } from './router';

describe('router', () => {
    afterEach(() => {
        vi.unstubAllEnvs();
        document.head.querySelector('meta[name="bb-dev-routes"]')?.remove();
    });

    it('CAP-004 loads each feature\'s routes.ts by glob', () => {
        expect(featureRoutes.map((r) => r.path)).toEqual(expect.arrayContaining(['/account', '/dev/gallery', '/dev/screens/:name']));
    });

    it('CAP-004 drops the dev routes unless the server turns them on', () => {
        const paths = (dev: boolean) => routesFor(dev).map((r) => r.path);

        expect(paths(true)).toEqual(expect.arrayContaining(['/dev/gallery', '/dev/screens/:name']));
        expect(paths(false)).not.toContain('/dev/gallery');
        expect(paths(false)).not.toContain('/dev/screens/:name');
        expect(paths(false)).toContain('/account');
    });

    it('CAP-004 sends a dev path to not-found when the dev routes are off', async () => {
        const router = makeRouter(false, createMemoryHistory());
        await router.push('/dev/gallery');

        expect(router.currentRoute.value.matched[0]?.path).toBe('/:pathMatch(.*)*');
    });

    it('CAP-004 leaves the dev routes out of a production build', async () => {
        vi.stubEnv('DEV', false);
        vi.resetModules();
        const { featureRoutes: production } = await import('./router');

        expect(production.map((r) => r.path)).toContain('/account');
        expect(production.some((r) => r.meta?.dev === true)).toBe(false);
    });

    it('CAP-004 turns the dev routes on only when the shell carries the server\'s flag', () => {
        expect(devRoutesEnabled()).toBe(false);

        const meta = document.createElement('meta');
        meta.name = 'bb-dev-routes';
        meta.content = '1';
        document.head.append(meta);
        expect(devRoutesEnabled()).toBe(true);

        meta.content = '0';
        expect(devRoutesEnabled()).toBe(false);
    });
});
