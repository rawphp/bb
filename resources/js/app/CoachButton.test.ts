import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import { read } from '@/app/call';
import type { AgentStatusShowOutput } from '@/contracts/agent_status_show';
import { screenFixture } from '@/testing/schemas';
import CoachButton from './CoachButton.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));
// The sheet's own reads are CoachChat's; here only whether it opens matters.
vi.mock('@/features/coach/CoachChat.vue', () => ({
    default: { props: ['open'], template: '<div v-if="open" data-test="chat" />' },
}));

const readMock = vi.mocked(read);
const status = screenFixture<AgentStatusShowOutput>('agent_status_show');
let router: Router;

async function show(answer: AgentStatusShowOutput) {
    readMock.mockResolvedValue(answer);
    router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:any(.*)*', component: { template: '<p />' } }] });
    await router.push('/today');
    const wrapper = mount(CoachButton, { global: { plugins: [router] } });
    await flushPromises();
    return wrapper;
}

describe('CoachButton', () => {
    beforeEach(() => readMock.mockReset());

    it('AGT-002 shows when the agent is enabled and on, and opens the chat', async () => {
        const wrapper = await show(status);

        expect(readMock).toHaveBeenCalledWith('agent_status_show', {}, expect.any(Function), expect.any(Function));
        expect(wrapper.find('[data-test="chat"]').exists()).toBe(false);
        await wrapper.get('[data-test="coach"]').trigger('click');

        expect(wrapper.find('[data-test="chat"]').exists()).toBe(true);
    });

    it('AGT-002 is hidden while the agent is not enabled on this server', async () => {
        const wrapper = await show({ ...status, enabled: false });

        expect(wrapper.find('[data-test="coach"]').exists()).toBe(false);
    });

    it('AGT-020 is hidden while the user has turned their Coach off', async () => {
        const wrapper = await show({ ...status, on: false });

        expect(wrapper.find('[data-test="coach"]').exists()).toBe(false);
    });

    it('AGT-020 reads again on the next screen, so turning the Coach off in Settings hides it', async () => {
        const wrapper = await show(status);
        expect(wrapper.find('[data-test="coach"]').exists()).toBe(true);

        readMock.mockResolvedValue({ ...status, on: false });
        await router.push('/more');
        await flushPromises();

        expect(wrapper.find('[data-test="coach"]').exists()).toBe(false);
    });

    it('AGT-002 is hidden when the status cannot be read', async () => {
        readMock.mockRejectedValue(new Error('offline'));
        router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:any(.*)*', component: { template: '<p />' } }] });
        await router.push('/today');
        const wrapper = mount(CoachButton, { global: { plugins: [router] } });
        await flushPromises();

        expect(wrapper.find('[data-test="coach"]').exists()).toBe(false);
    });
});
