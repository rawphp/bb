import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, RouterView } from 'vue-router';
import { read } from '@/app/call';
import { featureRoutes, routesFor } from '@/app/router';
import { findScreen } from '../dev/screens';
import routes from './routes';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));

describe('nutrition routes', () => {
    it('VIEW-001 /dev/screens/nutrition_show finds its screen', () => {
        expect(findScreen('nutrition_show', featureRoutes)).not.toBeNull();
    });

    it("NAV-001 /nutrition is the Nutrition tab's own root, in place of the placeholder", async () => {
        vi.mocked(read).mockReset().mockReturnValueOnce(new Promise(() => {}));
        const router = createRouter({ history: createMemoryHistory(), routes });
        await router.push('/nutrition');
        mount(RouterView, { global: { plugins: [router] } });
        await flushPromises();

        expect(router.currentRoute.value.meta.tab).toBe('nutrition');
        expect(read).toHaveBeenCalledWith('nutrition_show', {}, expect.any(Function), expect.any(Function));
        expect(routesFor(false).filter((route) => route.path === '/nutrition').map((route) => route.name)).toEqual(['nutrition']);
    });
});
