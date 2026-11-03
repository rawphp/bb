import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, RouterView } from 'vue-router';
import { CapabilityError, read } from '@/app/call';
import { featureRoutes } from '@/app/router';
import type { ActivityShowOutput } from '@/contracts/activity_show';
import content from '../../../../tests/fixtures/screens/activity_show.json';
import empty from '../../../../tests/fixtures/screens/activity_show.empty.json';
import warning from '../../../../tests/fixtures/screens/activity_show.warning.json';
import { findScreen } from '../dev/screens';
import ActivityView from './ActivityView.vue';
import routes from './routes';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));

const readMock = vi.mocked(read);

function mountActivity(props: { payload?: ActivityShowOutput } = {}) {
    return mount(ActivityView, { props });
}

describe('ActivityView', () => {
    beforeEach(() => readMock.mockReset());

    it('CAP-022 lists each recent change with its channel, when it happened and what came of it, without calling the server', () => {
        const wrapper = mountActivity({ payload: content as ActivityShowOutput });

        expect(wrapper.find('h1').text()).toBe('Activity');
        const entries = wrapper.findAll('[data-test="entry"]');
        expect(entries).toHaveLength(5);
        expect(entries[0]!.text()).toContain('Logged set 3 of Bench Press, 100 kg × 8');
        expect(entries[0]!.text()).toContain('App · Today, 18:14');
        expect(entries[0]!.text()).toContain('Done');
        expect(entries.map((entry) => entry.get('[data-test="channel"]').text())).toEqual([
            'App · Today, 18:14',
            'Coach · Today, 08:02',
            'Health bridge · Today, 06:02',
            'MCP · Yesterday, 21:30',
            'CLI · Yesterday, 12:45',
        ]);
        expect(readMock).not.toHaveBeenCalled();
    });

    it('CAP-022 shows a refused change in the tone and words the server sent', () => {
        const wrapper = mountActivity({ payload: warning as ActivityShowOutput });
        const refused = wrapper.findAll('[data-test="entry"]')[1]!;

        expect(refused.text()).toContain('Refused: this workout was already finished');
        expect(refused.get('[data-test="result"]').classes()).toContain('text-tone-warning');
    });

    it('CAP-022 says so when nothing has changed yet', () => {
        const wrapper = mountActivity({ payload: empty as ActivityShowOutput });

        expect(wrapper.findAll('[data-test="entry"]')).toHaveLength(0);
        expect(wrapper.get('[data-test="empty"]').text()).toBe('No changes yet.');
    });

    it('CAP-004 shows a skeleton while it reads activity_show, then the list', async () => {
        readMock.mockResolvedValueOnce(content);
        const wrapper = mountActivity();

        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('activity_show', {}, expect.any(Function), expect.any(Function));
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(false);
        expect(wrapper.findAll('[data-test="entry"]')).toHaveLength(5);
    });

    it.each([
        [new CapabilityError('activity_show', 401, null), 'You are signed out.'],
        [new CapabilityError('activity_show', 500, null), 'Activity could not be loaded.'],
    ])('CAP-004 says why the list did not load', async (error, message) => {
        readMock.mockRejectedValueOnce(error);
        const wrapper = mountActivity();
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain(message);
    });

    it('CAP-010 Try again reads the list again after a failed read', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('activity_show', 500, null)).mockResolvedValueOnce(content);
        const wrapper = mountActivity();
        await flushPromises();

        await wrapper.get('[data-test="retry"]').trigger('click');
        await flushPromises();

        expect(readMock).toHaveBeenCalledTimes(2);
        expect(wrapper.findAll('[data-test="entry"]')).toHaveLength(5);
    });

    it('OFF-003 offline with nothing cached says Activity needs a connection', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('activity_show', 0, null));
        const wrapper = mountActivity();
        await flushPromises();

        expect(wrapper.text()).toContain("Activity will load when you're back online.");
    });
});

describe('activity route', () => {
    it('VIEW-001 /dev/screens/activity_show finds the Activity screen', () => {
        expect(findScreen('activity_show', featureRoutes)).not.toBeNull();
    });

    it('CAP-022 /activity opens in the More tab and reads activity_show', async () => {
        readMock.mockReturnValueOnce(new Promise(() => {}));
        const router = createRouter({ history: createMemoryHistory(), routes });
        await router.push('/activity');
        mount(RouterView, { global: { plugins: [router] } });
        await flushPromises();

        expect(router.currentRoute.value.meta.tab).toBe('more');
        expect(readMock).toHaveBeenCalledWith('activity_show', {}, expect.any(Function), expect.any(Function));
    });
});
