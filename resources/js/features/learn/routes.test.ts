import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, RouterView } from 'vue-router';
import { read } from '@/app/call';
import { featureRoutes } from '@/app/router';
import { findScreen } from '../dev/screens';
import routes from './routes';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));

describe('learn routes', () => {
    it.each(['learn_show', 'article_show'])('VIEW-001 /dev/screens/%s finds its Learn screen', (name) => {
        expect(findScreen(name, featureRoutes)).not.toBeNull();
    });

    it('LRN-003 /learn/:slug opens ArticleView, which reads article_show for that slug', async () => {
        vi.mocked(read).mockReturnValueOnce(new Promise(() => {}));
        const router = createRouter({ history: createMemoryHistory(), routes });
        await router.push('/learn/rir');
        mount(RouterView, { global: { plugins: [router] } });
        await flushPromises();

        expect(read).toHaveBeenCalledWith('article_show', { slug: 'rir' }, expect.any(Function), expect.any(Function));
    });
});
