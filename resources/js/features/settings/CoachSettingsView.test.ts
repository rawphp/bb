import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, write } from '@/app/call';
import type { AgentStatusShowOutput } from '@/contracts/agent_status_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import CoachSettingsView from './CoachSettingsView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), write: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(write);
const on = screenFixture<AgentStatusShowOutput>('agent_status_show');
const off = screenFixture<AgentStatusShowOutput>('agent_status_show.empty');
const nearCap = screenFixture<AgentStatusShowOutput>('agent_status_show.warning');

function sent(capability: string): unknown {
    const call = writeMock.mock.calls.find(([name]) => name === capability)!;
    expect(inputViolations(capability, call[1])).toEqual([]);
    return call[1];
}

describe('CoachSettingsView', () => {
    beforeEach(() => {
        readMock.mockReset();
        writeMock.mockReset();
    });

    it('AGT-020 shows the Coach on, with a note on what it can and cannot do', () => {
        const wrapper = mount(CoachSettingsView, { props: { payload: on } });

        expect(wrapper.get<HTMLInputElement>('[role="switch"]').element.checked).toBe(true);
        expect(wrapper.text()).toContain('What the Coach can do');
        expect(wrapper.text()).toContain('What it cannot do');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('AGT-020 shows the Coach off', () => {
        const wrapper = mount(CoachSettingsView, { props: { payload: off } });

        expect(wrapper.get<HTMLInputElement>('[role="switch"]').element.checked).toBe(false);
    });

    it('AGT-005 shows how much Coach use is left, in the server\'s words', () => {
        const wrapper = mount(CoachSettingsView, { props: { payload: nearCap } });

        expect(wrapper.text()).toContain('3 Coach messages left today.');
    });

    it.each([
        [on, false],
        [off, true],
    ])('AGT-020 turns the Coach on or off and reads the screen again', async (payload, turnsOn) => {
        writeMock.mockResolvedValueOnce({ invalidates: ['agent_status_show'] });
        readMock.mockResolvedValueOnce({ ...payload, enabled: turnsOn });
        const wrapper = mount(CoachSettingsView, { props: { payload } });

        await wrapper.get('[role="switch"]').setValue(turnsOn);
        await flushPromises();

        expect(sent('coach_enabled_set')).toEqual({ on: turnsOn });
        expect(wrapper.get<HTMLInputElement>('[role="switch"]').element.checked).toBe(turnsOn);
    });

    it('AGT-020 keeps the switch where the user put it when the read still reports the instance flag', async () => {
        writeMock.mockResolvedValueOnce({ invalidates: ['agent_status_show'] });
        readMock.mockResolvedValueOnce(on);
        const wrapper = mount(CoachSettingsView, { props: { payload: on } });

        await wrapper.get('[role="switch"]').setValue(false);
        await flushPromises();

        expect(sent('coach_enabled_set')).toEqual({ on: false });
        expect(wrapper.get<HTMLInputElement>('[role="switch"]').element.checked).toBe(false);
    });

    it('CAP-012 puts the switch back and says why when the change is refused', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('coach_enabled_set', 0, { reason: 'network' }));
        const wrapper = mount(CoachSettingsView, { props: { payload: on } });

        await wrapper.get('[role="switch"]').setValue(false);
        await flushPromises();

        expect(wrapper.get<HTMLInputElement>('[role="switch"]').element.checked).toBe(true);
        expect(wrapper.get('[role="alert"]').text()).toContain('No connection');
    });

    it('AGT-020 clears the conversation after the user confirms', async () => {
        writeMock.mockResolvedValueOnce({ invalidates: [], toast: 'Conversation cleared.' });
        const wrapper = mount(CoachSettingsView, { props: { payload: on } });

        await wrapper.get('[data-test="clear"]').trigger('click');
        expect(writeMock).not.toHaveBeenCalled();
        await wrapper.get('[data-test="confirm-clear"]').trigger('click');
        await flushPromises();

        expect(sent('coach_conversation_clear')).toEqual({});
        expect(wrapper.text()).toContain('Conversation cleared.');
    });

    it('PWA-024 shows a skeleton while it loads', () => {
        readMock.mockReturnValueOnce(new Promise(() => {}));
        const wrapper = mount(CoachSettingsView);

        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        expect(readMock).toHaveBeenCalledWith('agent_status_show', {}, expect.any(Function), expect.any(Function));
    });

    it('CAP-004 says the Coach settings did not load', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('agent_status_show', 500, null));
        const wrapper = mount(CoachSettingsView);
        await flushPromises();

        expect(wrapper.text()).toContain('The Coach settings could not be loaded.');
    });
});
