import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, RouterView } from 'vue-router';
import { read } from '@/app/call';
import { featureRoutes, routesFor } from '@/app/router';
import { findScreen } from '../dev/screens';
import CustomFoodsView from './CustomFoodsView.vue';
import routes from './routes';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));

describe('custom foods routes', () => {
    it('VIEW-001 /dev/screens/custom_foods_show finds its screen', () => {
        expect(findScreen('custom_foods_show', featureRoutes)).not.toBeNull();
    });

    it('FOOD-061 /custom-foods opens in the More tab and reads custom_foods_show', async () => {
        vi.mocked(read).mockReset().mockReturnValueOnce(new Promise(() => {}));
        const router = createRouter({ history: createMemoryHistory(), routes });
        await router.push('/custom-foods');
        mount(RouterView, { global: { plugins: [router] } });
        await flushPromises();

        expect(router.currentRoute.value.meta.tab).toBe('more');
        expect(read).toHaveBeenCalledWith('custom_foods_show', {}, expect.any(Function), expect.any(Function));
    });

    it('FOOD-062 the Pantry\'s /nutrition/custom-foods/new?name= opens a new custom food named after the search', async () => {
        vi.mocked(read).mockReset().mockReturnValueOnce(new Promise(() => {}));
        const router = createRouter({ history: createMemoryHistory(), routes });
        await router.push({ path: '/nutrition/custom-foods/new', query: { name: 'chicken' } });
        const wrapper = mount(RouterView, { global: { plugins: [router] }, attachTo: document.body });
        await flushPromises();

        expect(wrapper.findComponent(CustomFoodsView).props('create')).toBe('chicken');
        expect(document.body.querySelector('[role="dialog"] h2')!.textContent).toBe('New custom food');
        expect(document.body.querySelector<HTMLInputElement>('[name="name"]')!.value).toBe('chicken');
        wrapper.unmount();
        document.body.innerHTML = '';
    });

    it('FOOD-062 without a name it opens a blank new custom food', async () => {
        vi.mocked(read).mockReset().mockReturnValueOnce(new Promise(() => {}));
        const router = createRouter({ history: createMemoryHistory(), routes });
        await router.push('/nutrition/custom-foods/new');
        const wrapper = mount(RouterView, { global: { plugins: [router] } });
        await flushPromises();

        expect(wrapper.findComponent(CustomFoodsView).props('create')).toBe('');
        wrapper.unmount();
        document.body.innerHTML = '';
    });

    it('FOOD-062 the app routes the path the Pantry links to', () => {
        expect(routesFor(false).find((route) => route.path === '/nutrition/custom-foods/new')).toBeDefined();
    });
});
