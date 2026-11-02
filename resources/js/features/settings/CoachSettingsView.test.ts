import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, writeNow } from '@/app/call';
import type { AgentStatusShowOutput } from '@/contracts/agent_status_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import { toneClasses } from '@/design/tones';
import CoachSettingsView from './CoachSettingsView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), writeNow: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(writeNow);
const fixture = screenFixture<AgentStatusShowOutput>('agent_status_show');
const nearCap = screenFixture<AgentStatusShowOutput>('agent_status_show.warning');
// The user's own setting, which #49 left for the coach_enabled_set API to add as `on`.
const on = { ...fixture, on: true };
const off = { ...fixture, on: false };

function sent(capability: string): unknown {
    const call = writeMock.mock.calls.find(([name]) => name === capability)!;
    expect(inputViolations(capability, call[1])).toEqual([]);
    return call[1];
}

// The confirmations are BottomSheets, which teleport to <body>; render them in place.
const global = { stubs: { teleport: true } };

describe('CoachSettingsView', () => {
    beforeEach(() => {
        readMock.mockReset();
        writeMock.mockReset();
    });

    it('AGT-020 shows no switch while the read does not carry the user\'s own setting', () => {
        // enabled is the instance's flag, so it must not stand in for the user's choice.
        const wrapper = mount(CoachSettingsView, { global, props: { payload: fixture } });

        expect(wrapper.find('[role="switch"]').exists()).toBe(false);
        expect(wrapper.get('[data-test="no-switch"]').text()).toContain("isn't available on this server yet");
        expect(wrapper.text()).toContain('What the Coach can do');
    });

    it('AGT-020 shows the Coach on, with a note on what it can and cannot do', () => {
        const wrapper = mount(CoachSettingsView, { global, props: { payload: on } });

        expect(wrapper.get<HTMLInputElement>('[role="switch"]').element.checked).toBe(true);
        expect(wrapper.text()).toContain('What the Coach can do');
        expect(wrapper.text()).toContain('What it cannot do');
        // CAP-031 excludes only deleting the account, tokens and billing; the note promises no more.
        expect(wrapper.text()).not.toContain('health screening');
        // Every CAP-031 exclusion is named: account_delete, token_create, token_revoke, billing_*.
        expect(wrapper.text()).toContain('delete your account, make or revoke tokens or change your subscription');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('AGT-020 shows the Coach off', () => {
        const wrapper = mount(CoachSettingsView, { global, props: { payload: off } });

        expect(wrapper.get<HTMLInputElement>('[role="switch"]').element.checked).toBe(false);
    });

    it('AGT-005 shows how much Coach use is left, in the server\'s words', () => {
        const wrapper = mount(CoachSettingsView, { global, props: { payload: nearCap } });

        expect(wrapper.text()).toContain('3 Coach messages left today.');
    });

    it.each(['info', 'warning'] as const)('CAP-018 shows the notice in the %s tone the server picked', (tone) => {
        const wrapper = mount(CoachSettingsView, { global, props: { payload: { ...nearCap, notice_status: { text: '3 Coach messages left today.', tone } } } });

        expect(wrapper.get('[data-test="notice"]').classes()).toEqual(expect.arrayContaining(toneClasses[tone].split(' ')));
    });

    it.each([
        [on, false],
        [off, true],
    ])('AGT-020 turns the Coach on or off and reads the screen again', async (payload, turnsOn) => {
        writeMock.mockResolvedValueOnce({ invalidates: ['agent_status_show'] });
        readMock.mockResolvedValueOnce({ ...payload, on: turnsOn });
        const wrapper = mount(CoachSettingsView, { global, props: { payload } });

        await wrapper.get('[role="switch"]').setValue(turnsOn);
        await flushPromises();

        expect(sent('coach_enabled_set')).toEqual({ on: turnsOn });
        expect(wrapper.get<HTMLInputElement>('[role="switch"]').element.checked).toBe(turnsOn);
    });

    it('AGT-020 shows what the server holds after the change, not what was tapped', async () => {
        writeMock.mockResolvedValueOnce({ invalidates: ['agent_status_show'] });
        readMock.mockResolvedValueOnce(on);
        const wrapper = mount(CoachSettingsView, { global, props: { payload: on } });

        await wrapper.get('[role="switch"]').setValue(false);
        await flushPromises();

        expect(wrapper.get<HTMLInputElement>('[role="switch"]').element.checked).toBe(true);
    });

    it('CAP-012 puts the switch back and says why when the change is refused', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('coach_enabled_set', 0, { reason: 'network' }));
        const wrapper = mount(CoachSettingsView, { global, props: { payload: on } });

        await wrapper.get('[role="switch"]').setValue(false);
        await flushPromises();

        expect(wrapper.get<HTMLInputElement>('[role="switch"]').element.checked).toBe(true);
        expect(wrapper.get('[role="alert"]').text()).toContain('No connection');
    });

    it('AGT-020 clears the conversation after the user confirms', async () => {
        writeMock.mockResolvedValueOnce({ invalidates: [], toast: 'Conversation cleared.' });
        const wrapper = mount(CoachSettingsView, { global, props: { payload: on } });

        await wrapper.get('[data-test="clear"]').trigger('click');
        expect(writeMock).not.toHaveBeenCalled();
        await wrapper.get('[role="dialog"] [data-save]').trigger('click');
        await flushPromises();

        expect(sent('coach_conversation_clear')).toEqual({});
        expect(wrapper.text()).toContain('Conversation cleared.');
    });

    it('CAP-012 keeps the confirmation open and says why when clearing fails', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('coach_conversation_clear', 0, { reason: 'network' }));
        const wrapper = mount(CoachSettingsView, { global, props: { payload: on } });

        await wrapper.get('[data-test="clear"]').trigger('click');
        await wrapper.get('[role="dialog"] [data-save]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('No connection');
        expect(wrapper.find('[role="dialog"] [data-save]').exists()).toBe(true);
        expect(wrapper.text()).not.toContain('Conversation cleared.');
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
