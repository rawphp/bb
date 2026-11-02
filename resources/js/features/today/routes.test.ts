import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, RouterView } from 'vue-router';
import { read, write } from '@/app/call';
import { featureRoutes } from '@/app/router';
import { findScreen, takesFixture } from '../dev/screens';
import type { DayOptimizeShowOutput } from '@/contracts/day_optimize_show';
import { screenFixture } from '@/testing/schemas';
import routes from './routes';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), write: vi.fn() }));

describe('today routes', () => {
    it.each(['day_adjust_show', 'day_redistribute_show', 'day_optimize_show'])('VIEW-001 /dev/screens/%s finds its sheet and its fixture', (name) => {
        expect(findScreen(name, featureRoutes)).not.toBeNull();
        expect(takesFixture(name, featureRoutes)).toBe(true);
    });

    it('VIEW-001 /dev/screens/off_plan_log finds the off-plan sheet, which reads nothing', () => {
        expect(findScreen('off_plan_log', featureRoutes)).not.toBeNull();
        expect(takesFixture('off_plan_log', featureRoutes)).toBe(false);
    });

    it.each([
        ['/today/2026-09-23/adjust', 'day_adjust_show'],
        ['/today/2026-09-23/redistribute', 'day_redistribute_show'],
        ['/today/2026-09-23/optimize', 'day_optimize_show'],
    ])('DAY-020 %s opens its sheet, which reads %s for that day', async (path, name) => {
        vi.mocked(read).mockReturnValueOnce(new Promise(() => {}));
        const router = createRouter({ history: createMemoryHistory(), routes });
        await router.push(path);
        mount(RouterView, { global: { plugins: [router], stubs: { teleport: true } } });
        await flushPromises();

        expect(read).toHaveBeenCalledWith(name, { date: '2026-09-23' }, expect.any(Function), expect.any(Function));
    });

    it('DAY-020 a day that is not a date opens no sheet', () => {
        const router = createRouter({ history: createMemoryHistory(), routes });

        expect(router.resolve('/today/tomorrow/adjust').matched).toHaveLength(0);
    });

    async function open(path: string) {
        const router = createRouter({ history: createMemoryHistory(), routes: [...routes, { path: '/today', component: { template: '<p />' } }] });
        await router.push(path);
        const wrapper = mount(RouterView, { global: { plugins: [router], stubs: { teleport: true } } });
        await flushPromises();
        return { router, wrapper };
    }

    it('NAV-001 a sheet opened at its own route goes back to Today on Cancel', async () => {
        vi.mocked(read).mockReturnValueOnce(new Promise(() => {}));
        const { router, wrapper } = await open('/today/2026-09-23/adjust');

        await wrapper.get('[data-cancel]').trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.path).toBe('/today');
    });

    it('NAV-001 a sheet opened at its own route goes back to Today once it saves', async () => {
        vi.mocked(read).mockResolvedValueOnce(screenFixture<DayOptimizeShowOutput>('day_optimize_show'));
        vi.mocked(write).mockResolvedValueOnce({ invalidates: [], toast: 'Schedule optimized', undo: null });
        const { router, wrapper } = await open('/today/2026-09-23/optimize');

        await wrapper.get('[data-save]').trigger('click');
        await flushPromises();

        expect(write).toHaveBeenCalledWith('day_optimize', { date: '2026-09-23' }, expect.anything());
        expect(router.currentRoute.value.path).toBe('/today');
    });

    it('DAY-001 /today/:date/off-plan logs the food on that day, not the clock\'s', async () => {
        vi.mocked(write).mockResolvedValueOnce({ invalidates: [] });
        const { wrapper } = await open('/today/2026-09-20/off-plan');

        await wrapper.get('input[name="kcal"]').setValue('320');
        await wrapper.get('[data-save]').trigger('click');
        await flushPromises();

        expect(write).toHaveBeenCalledWith('off_plan_log', expect.objectContaining({ date: '2026-09-20', kcal: 320 }), expect.anything());
    });
});
