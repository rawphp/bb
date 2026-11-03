import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, RouterView } from 'vue-router';
import { read } from '@/app/call';
import { featureRoutes, routesFor } from '@/app/router';
import { findScreen } from '../dev/screens';
import WeighInScheduleView from './WeighInScheduleView.vue';
import WeighInsView from './WeighInsView.vue';
import routes from './routes';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));

async function open(path: string | { path: string; query: Record<string, string> }) {
    vi.mocked(read).mockReset().mockReturnValueOnce(new Promise(() => {}));
    const router = createRouter({ history: createMemoryHistory(), routes });
    await router.push(path);
    const wrapper = mount(RouterView, { global: { plugins: [router] } });
    await flushPromises();
    return { router, wrapper };
}

describe('weigh-ins routes', () => {
    it('VIEW-001 /dev/screens/weigh_ins_show finds the Weigh-ins screen, and ?view= the schedule', () => {
        expect(findScreen('weigh_ins_show', featureRoutes)).not.toBeNull();
        expect(findScreen('weigh_ins_show', featureRoutes, 'weigh-in-schedule')).not.toBeNull();
    });

    it('WT-004 /weigh-ins opens in the More tab and reads weigh_ins_show', async () => {
        const { router, wrapper } = await open('/weigh-ins');

        expect(router.currentRoute.value.meta.tab).toBe('more');
        expect(wrapper.findComponent(WeighInsView).props('log')).toBeUndefined();
        expect(read).toHaveBeenCalledWith('weigh_ins_show', {}, expect.any(Function), expect.any(Function));
    });

    it('WT-007 /weigh-ins/schedule opens the schedule in the More tab', async () => {
        const { router, wrapper } = await open('/weigh-ins/schedule');

        expect(router.currentRoute.value.meta.tab).toBe('more');
        expect(wrapper.findComponent(WeighInScheduleView).exists()).toBe(true);
    });

    it('WT-002 /weigh-ins/log?at= opens a new weigh-in at that time, in the tab it was opened from', async () => {
        const { router, wrapper } = await open({ path: '/weigh-ins/log', query: { at: '2026-10-07T07:00:00+01:00' } });

        expect(router.currentRoute.value.meta.tab).toBeUndefined();
        expect(wrapper.findComponent(WeighInsView).props('log')).toBe('2026-10-07T07:00:00+01:00');
    });

    it('WT-002 /weigh-ins/log with no time opens a new weigh-in for now', async () => {
        const { wrapper } = await open('/weigh-ins/log');

        expect(wrapper.findComponent(WeighInsView).props('log')).toBe('');
    });

    it('NAV-001 the More placeholder links Weigh-ins until the More menu exists', () => {
        const more = routesFor(false).find((route) => route.path === '/more');

        expect((more?.props as { links: string[] }).links).toContain('/weigh-ins');
        expect((more?.props as { links: string[] }).links).not.toContain('/weigh-ins/log');
    });
});
