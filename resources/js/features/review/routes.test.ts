import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, RouterView } from 'vue-router';
import { read } from '@/app/call';
import { featureRoutes } from '@/app/router';
import { findScreen } from '../dev/screens';
import routes from './routes';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));

describe('review routes', () => {
    it('VIEW-001 /dev/screens/review_show finds the weekly review', () => {
        expect(findScreen('review_show', featureRoutes)).not.toBeNull();
    });

    it('REV-001 /review opens the weekly review on the step the link names, reading review_show', async () => {
        vi.mocked(read).mockReturnValueOnce(new Promise(() => {}));
        const router = createRouter({ history: createMemoryHistory(), routes });
        await router.push('/review?step=calories');
        const wrapper = mount(RouterView, { global: { plugins: [router] } });
        await flushPromises();

        expect(read).toHaveBeenCalledWith('review_show', {}, expect.any(Function), expect.any(Function));
        expect(wrapper.findComponent({ name: 'WeeklyReviewView' }).props('step')).toBe('calories');
    });
});
