import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, RouterView } from 'vue-router';
import { read } from '@/app/call';
import { featureRoutes, routesFor } from '@/app/router';
import { findScreen } from '../dev/screens';
import routes from './routes';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));

function linksOf(tab: string): string[] {
    const root = routesFor(false).find((route) => route.path === tab);
    return (root?.props as { links: string[] } | undefined)?.links ?? [];
}

describe('shopping routes', () => {
    it('VIEW-001 /dev/screens/shopping_show finds its screen', () => {
        expect(findScreen('shopping_show', featureRoutes)).not.toBeNull();
    });

    it.each([
        ['/shopping', 'more'],
        ['/nutrition/shopping', 'nutrition'],
    ])('SHOP-006 %s opens in the %s tab and reads shopping_show', async (path, tab) => {
        vi.mocked(read).mockReset().mockReturnValueOnce(new Promise(() => {}));
        const router = createRouter({ history: createMemoryHistory(), routes });
        await router.push(path);
        mount(RouterView, { global: { plugins: [router] } });
        await flushPromises();

        expect(router.currentRoute.value.meta.tab).toBe(tab);
        expect(read).toHaveBeenCalledWith('shopping_show', {}, expect.any(Function), expect.any(Function));
    });

    it('SHOP-006 the More placeholder links the shopping list until the More menu exists', () => {
        expect(linksOf('/more')).toContain('/shopping');
    });
});
