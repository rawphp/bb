import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, RouterView } from 'vue-router';
import { read } from '@/app/call';
import { featureRoutes } from '@/app/router';
import { findScreen, takesFixture } from '../dev/screens';
import routes from './routes';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));

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
});
