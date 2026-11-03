import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, RouterView } from 'vue-router';
import { read } from '@/app/call';
import { featureRoutes } from '@/app/router';
import { findScreen } from '../dev/screens';
import routes from './routes';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));

async function open(path: string) {
    vi.mocked(read).mockReset().mockReturnValue(new Promise(() => {}));
    const router = createRouter({ history: createMemoryHistory(), routes });
    await router.push(path);
    const wrapper = mount(RouterView, { global: { plugins: [router] } });
    await flushPromises();

    return { router, wrapper };
}

describe('exercises routes', () => {
    it('VIEW-001 /dev/screens/exercises_show and /dev/screens/exercise_show find their screens', () => {
        expect(findScreen('exercises_show', featureRoutes)).not.toBeNull();
        expect(findScreen('exercise_show', featureRoutes)).not.toBeNull();
    });

    it('EX-001 /exercises, which Train links, opens in the Train tab and reads exercises_show', async () => {
        const { router, wrapper } = await open('/exercises');

        expect(router.currentRoute.value.meta.tab).toBe('train');
        expect(read).toHaveBeenCalledWith('exercises_show', {}, expect.any(Function), expect.any(Function));
        wrapper.unmount();
    });

    it('EX-002 /exercises/:exerciseId opens in the Train tab and reads exercise_show for that exercise', async () => {
        const { router, wrapper } = await open('/exercises/01JA8Q0V3K2M4N6P8R0T2W4Y6H');

        expect(router.currentRoute.value.meta.tab).toBe('train');
        expect(read).toHaveBeenCalledWith('exercise_show', { exercise_id: '01JA8Q0V3K2M4N6P8R0T2W4Y6H' }, expect.any(Function), expect.any(Function));
        wrapper.unmount();
    });
});
