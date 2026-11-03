import { describe, expect, it } from 'vitest';
import { featureRoutes, routesFor } from '@/app/router';
import { findScreen, routeProps } from '../dev/screens';
import routes from './routes';

describe('billing routes', () => {
    it.each(['paywall', 'paywall-sheet', 'lapsed-banner'])('VIEW-001 /dev/screens/billing_show?view=%s finds its screen', (view) => {
        expect(findScreen('billing_show', featureRoutes, view)).not.toBeNull();
    });

    it('SUB-004 the dev screen opens the paywall sheet open', () => {
        expect(routeProps('billing_show', featureRoutes, 'paywall-sheet')).toEqual({ open: true });
    });

    it('SUB-020 the billing screens are dev screens only: the app opens them from the screens that show them', () => {
        expect(routes.length).toBe(3);
        expect(routes.every((route) => route.meta?.dev === true && route.meta?.tab === undefined)).toBe(true);
        expect(routesFor(false).some((route) => route.meta?.screen === 'billing_show')).toBe(false);
    });
});
