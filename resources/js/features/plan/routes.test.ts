import { describe, expect, it } from 'vitest';
import { createMemoryHistory } from 'vue-router';
import { featureRoutes, makeRouter } from '@/app/router';
import { findScreen } from '../dev/screens';

describe('Plan routes', () => {
    it('NAV-001 puts add a phase, the 24-week plans and the phase transition in the Train tab', () => {
        const router = makeRouter(false, createMemoryHistory());

        for (const [path, name] of [
            ['/train/plan/add-phase', 'add-phase'],
            ['/train/plan/prebuilt', 'prebuilt-plans'],
            ['/train/plan/transition', 'phase-transition'],
        ] as const) {
            expect(router.resolve(path).name).toBe(name);
            expect(router.resolve(path).meta).toMatchObject({ screen: 'plan_show', tab: 'train' });
        }
    });

    it.each(['add-phase', 'prebuilt-plans', 'phase-transition'])('VIEW-001 /dev/screens/plan_show?view=%s finds its screen', (view) => {
        expect(findScreen('plan_show', featureRoutes, view)).not.toBeNull();
    });
});
