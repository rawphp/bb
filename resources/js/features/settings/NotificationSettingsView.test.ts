import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { computed, ref } from 'vue';
import { CapabilityError, read, writeNow } from '@/app/call';
import type { SettingsShowOutput } from '@/contracts/settings_show';
import type { PushState } from '@/features/notifications/usePush';
import { inputViolations, screenFixture } from '@/testing/schemas';
import NotificationSettingsView from './NotificationSettingsView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), writeNow: vi.fn() }));

const state = ref<PushState | null>(null);
const enable = vi.fn<() => Promise<PushState>>();
const refresh = vi.fn<() => Promise<PushState>>();
vi.mock('@/features/notifications/usePush', () => ({
    usePush: () => ({ state, needsHomeScreen: computed(() => state.value === 'needs-home-screen'), refresh, enable, disable: vi.fn() }),
}));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(writeNow);
const settings = screenFixture<SettingsShowOutput>('settings_show');

function render(payload?: SettingsShowOutput) {
    return mount(NotificationSettingsView, { props: payload ? { payload } : {} });
}

function sent(): unknown {
    const call = writeMock.mock.calls.find(([name]) => name === 'notification_prefs_set')!;
    expect(inputViolations('notification_prefs_set', call[1])).toEqual([]);
    return call[1];
}

function withMeals(on: boolean): SettingsShowOutput {
    return { ...settings, notifications: settings.notifications.map((type) => (type.kind === 'meal' ? { ...type, on } : type)) };
}

describe('NotificationSettingsView', () => {
    beforeEach(() => {
        readMock.mockReset();
        writeMock.mockReset();
        enable.mockReset();
        refresh.mockReset();
        refresh.mockImplementation(async () => (state.value = 'off'));
        state.value = null;
    });

    it('NOTIF-007 shows one switch per notification type, as the server holds them', () => {
        const wrapper = render(settings);

        const switches = wrapper.findAll<HTMLInputElement>('[role="switch"]');
        expect(switches.length).toBe(settings.notifications.length);
        expect(wrapper.text()).toContain('Weigh-ins');
        expect(wrapper.text()).toContain('Meal target changes');
        expect(wrapper.get<HTMLInputElement>('[data-test="notify-meal"]').element.checked).toBe(false);
        expect(wrapper.get<HTMLInputElement>('[data-test="notify-weigh_in"]').element.checked).toBe(true);
        expect(readMock).not.toHaveBeenCalled();
    });

    it('NOTIF-010 does not ask for permission when the page opens', async () => {
        render(settings);
        await flushPromises();

        expect(enable).not.toHaveBeenCalled();
    });

    it('NOTIF-010 asks for permission when a type is turned on, and saves the switch', async () => {
        enable.mockResolvedValueOnce('on');
        writeMock.mockResolvedValueOnce({ invalidates: ['settings_show'] });
        readMock.mockResolvedValueOnce(withMeals(true));
        const wrapper = render(settings);

        await wrapper.get('[data-test="notify-meal"]').setValue(true);
        await flushPromises();

        expect(enable).toHaveBeenCalledOnce();
        expect(sent()).toEqual({ kind: 'meal', on: true });
        expect(wrapper.get<HTMLInputElement>('[data-test="notify-meal"]').element.checked).toBe(true);
    });

    it('NOTIF-007 turns a type off without asking for anything', async () => {
        writeMock.mockResolvedValueOnce({ invalidates: ['settings_show'] });
        readMock.mockResolvedValueOnce(settings);
        const wrapper = render(settings);

        await wrapper.get('[data-test="notify-workout"]').setValue(false);
        await flushPromises();

        expect(enable).not.toHaveBeenCalled();
        expect(sent()).toEqual({ kind: 'workout', on: false });
    });

    it('NOTIF-010 on iPhone explains that notifications need the app on the home screen', async () => {
        refresh.mockImplementation(async () => (state.value = 'needs-home-screen'));
        const wrapper = render(settings);
        await flushPromises();

        expect(wrapper.text()).toContain('add Bodybuilder to your Home Screen');
    });

    it('NOTIF-010 says when the browser blocks notifications', async () => {
        refresh.mockImplementation(async () => (state.value = 'denied'));
        const wrapper = render(settings);
        await flushPromises();

        expect(wrapper.get('[data-test="push-blocked"]').text()).toContain("browser's settings");
    });

    it('NOTIF-010 still saves the switch when this device could not be set up, and says so', async () => {
        enable.mockRejectedValueOnce(new CapabilityError('push_subscribe', 0, { reason: 'network' }));
        writeMock.mockResolvedValueOnce({ invalidates: ['settings_show'] });
        readMock.mockResolvedValueOnce(withMeals(true));
        const wrapper = render(settings);

        await wrapper.get('[data-test="notify-meal"]').setValue(true);
        await flushPromises();

        expect(sent()).toEqual({ kind: 'meal', on: true });
        expect(wrapper.get('[data-test="push-error"]').text()).toContain('could not be set up');
    });

    it('NOTIF-010 starts the permission prompt at the tap, before the switch goes to the server', async () => {
        enable.mockResolvedValueOnce('on');
        let answer: (value: unknown) => void = () => {};
        writeMock.mockReturnValueOnce(new Promise((resolve) => (answer = resolve)));
        readMock.mockResolvedValueOnce(withMeals(true));
        const wrapper = render(settings);

        await wrapper.get('[data-test="notify-meal"]').setValue(true);

        expect(enable).toHaveBeenCalledOnce();
        expect(enable.mock.invocationCallOrder[0]).toBeLessThan(writeMock.mock.invocationCallOrder[0]!);
        answer({ invalidates: ['settings_show'] });
        await flushPromises();
    });

    it('NOTIF-007 saves the switch even when setting up this device never finishes', async () => {
        enable.mockReturnValueOnce(new Promise(() => {}));
        writeMock.mockResolvedValueOnce({ invalidates: ['settings_show'] });
        readMock.mockResolvedValueOnce(withMeals(true));
        const wrapper = render(settings);

        await wrapper.get('[data-test="notify-meal"]').setValue(true);
        await flushPromises();

        expect(enable).toHaveBeenCalledOnce();
        expect(sent()).toEqual({ kind: 'meal', on: true });
        expect(wrapper.get('[data-test="notify-workout"]').attributes('disabled')).toBeUndefined();
    });

    it('NOTIF-007 holds every switch while a change is with the server, so no tap is lost', async () => {
        let answer: (value: unknown) => void = () => {};
        writeMock.mockReturnValueOnce(new Promise((resolve) => (answer = resolve)));
        readMock.mockResolvedValueOnce(settings);
        const wrapper = render(settings);

        await wrapper.get('[data-test="notify-workout"]').setValue(false);
        expect(wrapper.get('[data-test="notify-meal"]').attributes('disabled')).toBeDefined();
        answer({ invalidates: ['settings_show'] });
        await flushPromises();

        expect(wrapper.get('[data-test="notify-meal"]').attributes('disabled')).toBeUndefined();
    });

    it.each(['settings_show.empty', 'settings_show.warning'])('VIEW-001 renders the %s fixture', (name) => {
        const payload = screenFixture<SettingsShowOutput>(name);
        const wrapper = render(payload);

        expect(wrapper.findAll('[role="switch"]').length).toBe(payload.notifications.length);
    });

    it('CAP-012 puts a refused switch back and says why', async () => {
        enable.mockResolvedValueOnce('on');
        writeMock.mockRejectedValueOnce(new CapabilityError('notification_prefs_set', 0, { reason: 'network' }));
        const wrapper = render(settings);

        await wrapper.get('[data-test="notify-meal"]').setValue(true);
        await flushPromises();

        expect(wrapper.get<HTMLInputElement>('[data-test="notify-meal"]').element.checked).toBe(false);
        expect(wrapper.get('[role="alert"]').text()).toContain('No connection');
    });

    it('PWA-024 shows a skeleton while it loads', () => {
        readMock.mockReturnValueOnce(new Promise(() => {}));

        expect(render().find('[data-test="skeleton"]').exists()).toBe(true);
    });

    it('CAP-004 says the notification settings did not load', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('settings_show', 500, null));
        const wrapper = render();
        await flushPromises();

        expect(wrapper.text()).toContain('Your notification settings could not be loaded.');
    });
});
