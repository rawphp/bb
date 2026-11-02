import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, RouterView } from 'vue-router';
import { read } from '@/app/call';
import { featureRoutes } from '@/app/router';
import { findScreen } from '../dev/screens';
import routes from './routes';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));

describe('pantry routes', () => {
    it('VIEW-001 /dev/screens/food_search finds the Pantry', () => {
        expect(findScreen('food_search', featureRoutes)).not.toBeNull();
    });

    it('FOOD-020 /nutrition/pantry opens the Pantry in the Nutrition tab, for the meal, search and group it is given', async () => {
        vi.mocked(read).mockReturnValueOnce(new Promise(() => {}));
        const router = createRouter({ history: createMemoryHistory(), routes });
        await router.push('/nutrition/pantry?meal=01JBX5M0000000000000000001&search=rice&group=carbs_recommended');
        mount(RouterView, { global: { plugins: [router] } });
        await flushPromises();

        expect(router.currentRoute.value.meta.tab).toBe('nutrition');
        expect(read).toHaveBeenCalledWith(
            'food_search',
            expect.objectContaining({ meal_id: '01JBX5M0000000000000000001', query: 'rice', group: 'carbs_recommended' }),
            expect.any(Function),
            expect.any(Function),
        );
    });

    it('FOOD-026 ignores a group the Pantry does not know', async () => {
        vi.mocked(read).mockReturnValueOnce(new Promise(() => {}));
        const router = createRouter({ history: createMemoryHistory(), routes });
        await router.push('/nutrition/pantry?group=cake');
        mount(RouterView, { global: { plugins: [router] } });
        await flushPromises();

        expect(read).toHaveBeenLastCalledWith('food_search', expect.objectContaining({ group: null, meal_id: null }), expect.any(Function), expect.any(Function));
    });
});
