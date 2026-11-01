import { describe, expect, it } from 'vitest';
import { createMemoryHistory } from 'vue-router';
import { featureRoutes, makeRouter, routesFor } from './router';

describe('router', () => {
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
});
