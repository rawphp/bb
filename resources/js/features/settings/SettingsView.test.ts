import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, postHost, read, readFresh, writeNow } from '@/app/call';
import type { SettingsShowOutput } from '@/contracts/settings_show';
import StatusPill from '@/design/StatusPill.vue';
import { toneClasses } from '@/design/tones';
import { inputViolations, screenFixture } from '@/testing/schemas';
import SettingsView from './SettingsView.vue';

vi.mock('@/app/call', async (original) => ({
    ...(await original<typeof import('@/app/call')>()),
    read: vi.fn(),
    readFresh: vi.fn(),
    writeNow: vi.fn(),
    postHost: vi.fn(),
}));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(writeNow);
const postMock = vi.mocked(postHost);
const settings = screenFixture<SettingsShowOutput>('settings_show');
const empty = screenFixture<SettingsShowOutput>('settings_show.empty');
const warning = screenFixture<SettingsShowOutput>('settings_show.warning');

function render(payload?: SettingsShowOutput) {
    return mount(SettingsView, { props: payload ? { payload } : {}, global: { stubs: { RouterLink: RouterLinkStub } } });
}

function sent(capability: string): unknown {
    const call = writeMock.mock.calls.find(([name]) => name === capability)!;
    expect(inputViolations(capability, call[1])).toEqual([]);
    return call[1];
}

function link(wrapper: ReturnType<typeof render>, to: string) {
    return wrapper.findAllComponents(RouterLinkStub).find((stub) => stub.props('to') === to);
}

describe('SettingsView', () => {
    const assign = vi.fn();

    beforeEach(() => {
        readMock.mockReset();
        writeMock.mockReset();
        postMock.mockReset();
        assign.mockReset();
        vi.stubGlobal('location', { ...window.location, assign });
        localStorage.clear();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        delete document.documentElement.dataset.appearance;
    });

    it('VIEW-001 renders the settings_show fixture in rp-clone\'s sections', () => {
        const wrapper = render(settings);

        for (const heading of ['Units', 'Display', 'Training', 'Schedule', 'Notifications', 'Integrations', 'Subscription', 'Support', 'Account', 'Developer']) {
            expect(wrapper.text()).toContain(heading);
        }
        expect(readMock).not.toHaveBeenCalled();
    });

    it('SET-004 links the profile, showing the name', () => {
        const wrapper = render(settings);

        expect(link(wrapper, '/settings/profile')!.text()).toContain('Sam');
    });

    it('SAFE-017 links Re-check eligibility with the server\'s status', () => {
        const wrapper = render(warning);

        expect(link(wrapper, '/settings/eligibility')!.text()).toContain('Training only');
    });

    it('SET-001 shows the units the server holds', () => {
        const wrapper = render(settings);

        expect(wrapper.get<HTMLSelectElement>('[data-test="weight_unit"]').element.value).toBe('kg');
        expect(wrapper.get<HTMLSelectElement>('[data-test="height_unit"]').element.value).toBe('cm');
        expect(wrapper.get('[data-test="height_unit"]').text()).toContain('ft and in');
    });

    it('SET-001 changes one unit and sends every setting with it', async () => {
        writeMock.mockResolvedValueOnce({ invalidates: ['settings_show'] });
        readMock.mockResolvedValueOnce({ ...settings, units: { ...settings.units, weight: { ...settings.units.weight, value: 'lb' } } });
        const wrapper = render(settings);

        await wrapper.get('[data-test="weight_unit"]').setValue('lb');
        await flushPromises();

        expect(sent('settings_update')).toEqual({ weight_unit: 'lb', height_unit: 'cm', appearance: 'system', consumed_totals: 'planned', micro_plates: false });
        expect(wrapper.get<HTMLSelectElement>('[data-test="weight_unit"]').element.value).toBe('lb');
    });

    it('SET-002 saves the appearance and repaints this device', async () => {
        writeMock.mockResolvedValueOnce({ invalidates: ['settings_show'] });
        readMock.mockResolvedValueOnce({ ...settings, appearance: { ...settings.appearance, value: 'dark' } });
        const wrapper = render(settings);

        await wrapper.get('[data-test="appearance"]').setValue('dark');
        await flushPromises();

        expect(sent('settings_update')).toMatchObject({ appearance: 'dark' });
        expect(document.documentElement.dataset.appearance).toBe('dark');
    });

    it('SET-003 saves the consumed totals choice', async () => {
        writeMock.mockResolvedValueOnce({ invalidates: ['settings_show'] });
        readMock.mockResolvedValueOnce(settings);
        const wrapper = render(settings);

        await wrapper.get('[data-test="consumed_totals"]').setValue('checked_in');
        await flushPromises();

        expect(sent('settings_update')).toMatchObject({ consumed_totals: 'checked_in' });
    });

    it('SET-001 turns micro plates on', async () => {
        writeMock.mockResolvedValueOnce({ invalidates: ['settings_show'] });
        readMock.mockResolvedValueOnce({ ...settings, micro_plates: true });
        const wrapper = render(settings);

        await wrapper.get('[data-test="micro_plates"]').setValue(true);
        await flushPromises();

        expect(sent('settings_update')).toMatchObject({ micro_plates: true });
        expect(wrapper.get<HTMLInputElement>('[data-test="micro_plates"]').element.checked).toBe(true);
    });

    it('CAP-012 puts a refused setting back and says why', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('settings_update', 422, { error: { code: 'validation_failed', message: 'That unit is not offered.' } }));
        const wrapper = render(settings);

        await wrapper.get('[data-test="weight_unit"]').setValue('lb');
        await flushPromises();

        expect(wrapper.get<HTMLSelectElement>('[data-test="weight_unit"]').element.value).toBe('kg');
        expect(wrapper.get('[role="alert"]').text()).toContain('That unit is not offered.');
        expect(document.documentElement.dataset.appearance).toBeUndefined();
    });

    it('CAP-012 puts a refused micro plates switch back', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('settings_update', 422, { error: { code: 'validation_failed', message: 'Micro plates could not be changed.' } }));
        const wrapper = render(settings);

        await wrapper.get('[data-test="micro_plates"]').setValue(true);
        await flushPromises();

        expect(wrapper.get<HTMLInputElement>('[data-test="micro_plates"]').element.checked).toBe(false);
        expect(wrapper.get('[role="alert"]').text()).toBe('Micro plates could not be changed.');
    });

    it('SET-002 paints this device with the appearance the server holds', () => {
        render({ ...settings, appearance: { ...settings.appearance, value: 'dark' } });

        expect(document.documentElement.dataset.appearance).toBe('dark');
    });

    it('SET-002 paints the appearance the server took after a change with no final answer', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('settings_update', 0, { reason: 'timeout' }));
        // A write with no final answer reads the server's copy, not the cache.
        vi.mocked(readFresh).mockResolvedValueOnce({ ...settings, appearance: { ...settings.appearance, value: 'light' } });
        const wrapper = render(settings);

        await wrapper.get('[data-test="appearance"]').setValue('light');
        await flushPromises();

        expect(document.documentElement.dataset.appearance).toBe('light');
    });

    it('SET-006 links the diet filters with their summary', () => {
        expect(link(render(settings), '/settings/diet-filters')!.text()).toContain('Dairy free');
        expect(link(render(empty), '/settings/diet-filters')!.text()).toContain('None');
    });

    it('SET-005 links the schedule defaults', () => {
        expect(link(render(settings), '/settings/schedule')).toBeDefined();
    });

    it('NOTIF-007 links the notification switches', () => {
        expect(link(render(settings), '/settings/notifications')).toBeDefined();
    });

    it('SET-010 links the Health bridge, tokens and Coach pages with their status', () => {
        const wrapper = render(settings);

        expect(link(wrapper, '/settings/health-bridge')!.text()).toContain('Last post today, 06:02');
        expect(link(wrapper, '/settings/tokens')!.text()).toContain('2 active');
        expect(link(wrapper, '/settings/coach')!.text()).toContain('On');
    });

    it('AGT-002 leaves the Coach row out when the agent is off', () => {
        expect(link(render(empty), '/settings/coach')).toBeUndefined();
    });

    it('SUB-020 says nothing of a subscription with billing off', () => {
        const wrapper = render({ ...settings, subscription: null });

        expect(wrapper.text()).not.toContain('Subscription');
        expect(wrapper.find('[data-test="manage-subscription"]').exists()).toBe(false);
    });

    it('SET-007 shows the subscription in the server\'s words', () => {
        const wrapper = render(warning);

        expect(wrapper.get('[data-test="subscription"]').text()).toContain('Ended');
        expect(wrapper.get('[data-test="subscription"]').getComponent(StatusPill).props('tone')).toBe('warning');
    });

    it('SUB-007 Manage subscription opens the Stripe portal', async () => {
        writeMock.mockResolvedValueOnce({ invalidates: [], url: 'https://billing.stripe.com/p/session/test' });
        const wrapper = render(settings);

        await wrapper.get('[data-test="manage-subscription"]').trigger('click');
        await flushPromises();

        expect(sent('billing_portal_open')).toEqual({});
        expect(assign).toHaveBeenCalledWith('https://billing.stripe.com/p/session/test');
    });

    it('CAP-012 says why the portal did not open', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('billing_portal_open', 0, { reason: 'network' }));
        const wrapper = render(settings);

        await wrapper.get('[data-test="manage-subscription"]').trigger('click');
        await flushPromises();

        expect(assign).not.toHaveBeenCalled();
        expect(wrapper.get('[role="alert"]').text()).toContain('No connection');
    });

    it('SET-008 links Help and a mail to support', () => {
        const wrapper = render(settings);

        expect(link(wrapper, '/learn')).toBeDefined();
        expect(wrapper.get('a[href="mailto:support@example.com"]').text()).toContain('support@example.com');
    });

    it('SET-009 shows who is signed in and links Delete account', () => {
        const wrapper = render(settings);

        expect(wrapper.text()).toContain('sam@example.com');
        expect(link(wrapper, '/settings/delete-account')).toBeDefined();
    });

    it('SET-009 Signed in as opens the account screen, which the More menu does not list', () => {
        const wrapper = render(settings);

        expect(link(wrapper, '/account')?.text()).toContain('sam@example.com');
    });

    it('ACC-006 signs out and opens the sign-in screen', async () => {
        postMock.mockResolvedValueOnce();
        const wrapper = render(settings);

        await wrapper.get('[data-test="sign-out"]').trigger('click');
        await flushPromises();

        expect(postMock).toHaveBeenCalledWith('/sign-out', {});
        expect(assign).toHaveBeenCalledWith('/sign-in');
    });

    it('CAP-012 stays signed in and says why when signing out fails', async () => {
        postMock.mockRejectedValueOnce(new CapabilityError('/sign-out', 0, { reason: 'network' }));
        const wrapper = render(settings);

        await wrapper.get('[data-test="sign-out"]').trigger('click');
        await flushPromises();

        expect(assign).not.toHaveBeenCalled();
        expect(wrapper.get('[role="alert"]').text()).toContain('No connection');
    });

    it('SET-011 shows the developer clock only where the server says so', () => {
        expect(render(settings).text()).toContain('Clock');
        expect(render(empty).text()).not.toContain('Developer');
    });

    it('CAP-018 shows the last save\'s notice in the server\'s tone', () => {
        const wrapper = render(warning);

        expect(wrapper.get('[data-test="notice"]').text()).toContain('your targets could not be updated');
        expect(wrapper.get('[data-test="notice"]').classes()).toEqual(expect.arrayContaining(toneClasses.warning.split(' ')));
        expect(render(settings).find('[data-test="notice"]').exists()).toBe(false);
    });

    it('PWA-024 shows a skeleton while it loads', () => {
        readMock.mockReturnValueOnce(new Promise(() => {}));
        const wrapper = render();

        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        expect(readMock).toHaveBeenCalledWith('settings_show', {}, expect.any(Function), expect.any(Function));
    });

    it('CAP-004 says the settings did not load', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('settings_show', 500, null));
        const wrapper = render();
        await flushPromises();

        expect(wrapper.text()).toContain('Your settings could not be loaded.');
        expect(wrapper.find('[data-test="retry"]').exists()).toBe(true);
    });
});
