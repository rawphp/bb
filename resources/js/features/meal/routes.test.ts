import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, RouterView } from 'vue-router';
import { read } from '@/app/call';
import { featureRoutes } from '@/app/router';
import { findScreen } from '../dev/screens';
import routes from './routes';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));

describe('meal routes', () => {
    it('VIEW-001 /dev/screens/meal_show finds its screen', () => {
        expect(findScreen('meal_show', featureRoutes)).not.toBeNull();
    });

    it('NAV-002 /meals/:id opens the meal screen for that meal, in the Nutrition tab', async () => {
        vi.mocked(read).mockReset().mockReturnValueOnce(new Promise(() => {}));
        const router = createRouter({ history: createMemoryHistory(), routes });
        await router.push('/meals/01JBX5M0000000000000000003');
        mount(RouterView, { global: { plugins: [router] } });
        await flushPromises();

        expect(router.currentRoute.value.meta.tab).toBe('nutrition');
        expect(read).toHaveBeenCalledWith('meal_show', { meal_id: '01JBX5M0000000000000000003' }, expect.any(Function), expect.any(Function));
    });

    it("MEAL-070 /meals/:id?action= hands Today's menu item to the meal screen", async () => {
        const router = createRouter({ history: createMemoryHistory(), routes });
        await router.push('/meals/01JBX5M0000000000000000003?action=load_saved_meal');

        const props = router.currentRoute.value.matched[0]!.props.default as (r: typeof router.currentRoute.value) => Record<string, unknown>;
        expect(props(router.currentRoute.value)).toEqual({ mealId: '01JBX5M0000000000000000003', action: 'load_saved_meal' });
    });
});
