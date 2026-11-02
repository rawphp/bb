import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read } from '@/app/call';
import type { ArticleShowOutput } from '@/contracts/article_show';
import article from '../../../../tests/fixtures/screens/article_show.json';
import ArticleView from './ArticleView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));

const readMock = vi.mocked(read);
const payload = article as ArticleShowOutput;

describe('ArticleView', () => {
    beforeEach(() => readMock.mockReset());

    it('LRN-001 renders the article title and its blocks from the fixture, without calling the server', () => {
        const wrapper = mount(ArticleView, { props: { payload } });

        expect(wrapper.find('h1').text()).toBe('Reps in reserve (RIR)');
        expect(wrapper.find('p').text()).toContain('RIR means reps in reserve');
        expect(wrapper.find('h2').text()).toBe('What the numbers mean');
        const bullets = wrapper.findAll('li > span:not([aria-hidden])');
        expect(bullets.map((li) => li.text())).toEqual([
            '3 RIR: you stopped with 3 reps left in the tank.',
            '0 RIR: you hit your limit. No more clean reps.',
        ]);
        expect(readMock).not.toHaveBeenCalled();
    });

    it('LRN-001 renders bold and italic spans', () => {
        const wrapper = mount(ArticleView, { props: { payload } });

        expect(wrapper.findAll('strong').map((s) => s.text())).toEqual(['3 RIR:', '0 RIR:']);
        expect(wrapper.findAll('em').map((s) => s.text())).toEqual(['No more clean reps.']);
    });

    it('LRN-001 an article with no blocks shows just its title', () => {
        const wrapper = mount(ArticleView, { props: { payload: { ...payload, blocks: [] } } });

        expect(wrapper.find('h1').text()).toBe('Reps in reserve (RIR)');
        expect(wrapper.findAll('p, li, h2')).toHaveLength(0);
    });

    it('CAP-004 shows a skeleton while it reads article_show for its slug, then the article', async () => {
        readMock.mockResolvedValueOnce(payload);
        const wrapper = mount(ArticleView, { props: { slug: 'rir' } });

        expect(wrapper.find('[aria-busy="true"]').exists()).toBe(true);
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('article_show', { slug: 'rir' }, expect.any(Function), expect.any(Function));
        expect(wrapper.find('[aria-busy="true"]').exists()).toBe(false);
        expect(wrapper.find('h1').text()).toBe('Reps in reserve (RIR)');
    });

    it.each([
        [new CapabilityError('article_show', 401, null), 'You are signed out.'],
        [new CapabilityError('article_show', 404, null), 'This article could not be loaded.'],
    ])('CAP-004 says why the article did not load', async (error, message) => {
        readMock.mockRejectedValueOnce(error);
        const wrapper = mount(ArticleView, { props: { slug: 'missing' } });
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toBe(message);
    });

    it('OFF-003 shows the cached article at once and drops it when the refresh answers 404', async () => {
        let refuse: (error: CapabilityError) => void = () => {};
        readMock.mockImplementationOnce(async (_name, _input, _onUpdate, onError) => {
            refuse = onError as typeof refuse;
            return payload;
        });
        const wrapper = mount(ArticleView, { props: { slug: 'rir' } });
        await flushPromises();
        expect(wrapper.text()).toContain('What the numbers mean');

        refuse(new CapabilityError('article_show', 404, null));
        await flushPromises();
        expect(wrapper.text()).not.toContain('What the numbers mean');
        expect(wrapper.text()).toContain('This article could not be loaded.');
    });
});
