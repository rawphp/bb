import { describe, expect, it } from 'vitest';
import { createMemoryHistory } from 'vue-router';
import { featureRoutes, makeRouter } from '@/app/router';
import { findScreen } from '@/features/dev/screens';

describe('Plan routes', () => {
    it('NAV-001 opens the plan, a phase and the running phase in the Train tab', () => {
        const router = makeRouter(false, createMemoryHistory());
        const name = (path: string) => router.resolve(path).name;

        expect(name('/plan')).toBe('plan');
        expect(name('/plan/phases/01JB8Q2V6N3XKZ4M5P7R9T1W2A')).toBe('phase');
        expect(name('/plan/current/length')).toBe('adjust-phase-length');
        expect(name('/plan/current/end')).toBe('end-current-phase');
        expect(router.resolve('/plan/phases/01JB8Q2V6N3XKZ4M5P7R9T1W2A').meta.tab).toBe('train');
        expect(router.resolve('/plan').meta.tab).toBe('train');
        expect(router.resolve('/plan/current/length').matched[0]!.props.default).toEqual({ action: 'adjust-length' });
        expect(router.resolve('/plan/current/end').matched[0]!.props.default).toEqual({ action: 'end' });
    });

    it('PLAN-004 opens a phase by the ULID or UUID the server gives it, and nothing else', () => {
        const router = makeRouter(false, createMemoryHistory());

        expect(router.resolve('/plan/phases/019a3c5e-9a56-71d1-9ef5-1f1b0bba368e').name).toBe('phase');
        for (const path of ['/plan/add', '/plan/prebuilt']) expect(router.resolve(path).name).not.toBe('phase');
        for (const path of ['/plan/phases/add', '/plan/phases/x']) {
            expect(router.resolve(path).matched[0]?.path).toBe('/:pathMatch(.*)*');
        }
    });

    it('CAP-002 opens the plan for plan_show and the phase detail for phase_show at /dev/screens', () => {
        expect(findScreen('plan_show', featureRoutes)).not.toBeNull();
        expect(findScreen('phase_show', featureRoutes)).not.toBeNull();
        expect(featureRoutes.find((route) => route.meta?.screen === 'plan_show')?.name).toBe('plan');
    });
});
