import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { CapabilityError, read } from '@/app/call';
import type { LearnShowOutput } from '@/contracts/learn_show';
import content from '../../../../tests/fixtures/screens/learn_show.json';
import empty from '../../../../tests/fixtures/screens/learn_show.empty.json';
import routes from './routes';
import LearnIndexView from './LearnIndexView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));

const readMock = vi.mocked(read);

function makeRouter() {
    // The feature's own paths, with a stand-in for each lazy screen.
    return createRouter({ history: createMemoryHistory(), routes: routes.map((route) => ({ ...route, component: { template: '<div />' } })) });
}

async function mountIndex(props: { payload?: LearnShowOutput } = {}) {
    const router = makeRouter();
    await router.push('/learn');
    return { router, wrapper: mount(LearnIndexView, { props, global: { plugins: [router] } }) };
}

describe('LearnIndexView', () => {
    beforeEach(() => readMock.mockReset());

    it('LRN-001 lists every article under its section title, from the fixture, without calling the server', async () => {
        const { wrapper } = await mountIndex({ payload: content });

        expect(wrapper.find('h1').text()).toBe('Learn');
        const sections = wrapper.findAll('section');
        expect(sections.map((s) => s.find('h2').text())).toEqual(['Using the app', 'Diet', 'Training', 'Training and diet together']);
        expect(sections[0]!.findAll('a').map((a) => a.text())).toEqual(['How this app differs from a tracker', 'Tools you need']);
        expect(readMock).not.toHaveBeenCalled();
    });

    it('LRN-001 each row opens its article', async () => {
        const { wrapper, router } = await mountIndex({ payload: content });
        const link = wrapper.findAll('a').find((a) => a.text() === 'Reps in reserve (RIR)')!;

        expect(link.attributes('href')).toBe('/learn/rir');
        await link.trigger('click');
        await flushPromises();
        expect(router.currentRoute.value.path).toBe('/learn/rir');
    });

    it('LRN-001 says so when there are no articles', async () => {
        const { wrapper } = await mountIndex({ payload: empty });

        expect(wrapper.findAll('section')).toHaveLength(0);
        expect(wrapper.text()).toContain('No articles yet.');
    });

    it('CAP-004 shows a skeleton while it reads learn_show, then the index', async () => {
        readMock.mockResolvedValueOnce(content);
        const { wrapper } = await mountIndex();

        expect(wrapper.find('[aria-busy="true"]').exists()).toBe(true);
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('learn_show', {}, expect.any(Function), expect.any(Function));
        expect(wrapper.find('[aria-busy="true"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Weighing in');
    });

    it.each([
        [new CapabilityError('learn_show', 401, null), 'You are signed out.'],
        [new CapabilityError('learn_show', 500, null), 'Learn could not be loaded.'],
    ])('CAP-004 says why the index did not load', async (error, message) => {
        readMock.mockRejectedValueOnce(error);
        const { wrapper } = await mountIndex();
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain(message);
    });

    it('CAP-010 Try again reads the index again after a failed read', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('learn_show', 500, null)).mockResolvedValueOnce(content as LearnShowOutput);
        const { wrapper } = await mountIndex();
        await flushPromises();

        await wrapper.get('[data-test="retry"]').trigger('click');
        await flushPromises();

        expect(readMock).toHaveBeenCalledTimes(2);
        expect(wrapper.text()).toContain('Weighing in');
    });

    it('OFF-003 offline with nothing cached says Learn needs a connection', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('learn_show', 0, null));
        const { wrapper } = await mountIndex();
        await flushPromises();

        expect(wrapper.text()).toContain('Not available offline');
        expect(wrapper.text()).toContain("Learn will load when you're back online.");
    });

    it('OFF-003 shows the cached index at once, then the refreshed one, and drops it when the refresh is refused', async () => {
        let refresh: (data: LearnShowOutput) => void = () => {};
        let refuse: (error: CapabilityError) => void = () => {};
        readMock.mockImplementationOnce(async (_name, _input, onUpdate, onError) => {
            refresh = onUpdate as typeof refresh;
            refuse = onError as typeof refuse;
            return empty;
        });
        const { wrapper } = await mountIndex();
        await flushPromises();
        expect(wrapper.text()).toContain('No articles yet.');

        refresh(content);
        await flushPromises();
        expect(wrapper.text()).toContain('Tools you need');

        refuse(new CapabilityError('learn_show', 401, null));
        await flushPromises();
        expect(wrapper.text()).not.toContain('Tools you need');
        expect(wrapper.text()).toContain('You are signed out.');
    });
});
