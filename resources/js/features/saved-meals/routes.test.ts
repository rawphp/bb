import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, RouterView } from 'vue-router';
import { read } from '@/app/call';
import { featureRoutes } from '@/app/router';
import { findScreen } from '../dev/screens';
import routes from './routes';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));

describe('saved meals routes', () => {
    it('VIEW-001 /dev/screens/saved_meals_show finds its screen', () => {
        expect(findScreen('saved_meals_show', featureRoutes)).not.toBeNull();
    });

    it('MEAL-071 /saved-meals opens in the More tab and reads saved_meals_show', async () => {
        vi.mocked(read).mockReset().mockReturnValueOnce(new Promise(() => {}));
        const router = createRouter({ history: createMemoryHistory(), routes });
        await router.push('/saved-meals');
        mount(RouterView, { global: { plugins: [router] } });
        await flushPromises();

        expect(router.currentRoute.value.meta.tab).toBe('more');
        expect(read).toHaveBeenCalledWith('saved_meals_show', {}, expect.any(Function), expect.any(Function));
    });
});
