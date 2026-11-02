import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, RouterView } from 'vue-router';
import { read } from '@/app/call';
import { featureRoutes } from '@/app/router';
import { findScreen } from '../dev/screens';
import customFoodRoutes from '../custom-foods/routes';
import savedMealRoutes from './routes';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));

describe('custom foods and saved meals routes', () => {
    it.each(['custom_foods_show', 'saved_meals_show'])('VIEW-001 /dev/screens/%s finds its screen', (name) => {
        expect(findScreen(name, featureRoutes)).not.toBeNull();
    });

    it.each([
        ['/custom-foods', customFoodRoutes, 'custom_foods_show'],
        ['/saved-meals', savedMealRoutes, 'saved_meals_show'],
    ])('FOOD-061 MEAL-071 %s opens in the More tab and reads %s', async (path, routes, name) => {
        vi.mocked(read).mockReset().mockReturnValueOnce(new Promise(() => {}));
        const router = createRouter({ history: createMemoryHistory(), routes });
        await router.push(path);
        mount(RouterView, { global: { plugins: [router] } });
        await flushPromises();

        expect(router.currentRoute.value.meta.tab).toBe('more');
        expect(read).toHaveBeenCalledWith(name, {}, expect.any(Function), expect.any(Function));
    });
});
