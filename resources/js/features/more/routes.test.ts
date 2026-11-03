import { describe, expect, it } from 'vitest';
import { featureRoutes, routesFor } from '@/app/router';
import { findScreen, takesFixture } from '../dev/screens';

describe('more routes', () => {
    it('VIEW-001 /dev/screens/more finds the More menu, which reads nothing', () => {
        expect(findScreen('more', featureRoutes)).not.toBeNull();
        expect(takesFixture('more', featureRoutes)).toBe(false);
    });

    it("NAV-001 /more is the More tab's own root, in place of the placeholder", () => {
        const roots = routesFor(false).filter((route) => route.path === '/more');

        expect(roots.map((route) => route.name)).toEqual(['more']);
        expect(roots[0]!.meta?.tab).toBe('more');
    });
});
