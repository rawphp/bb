import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, writeNow } from '@/app/call';
import type { SettingsShowOutput } from '@/contracts/settings_show';
import { toneClasses } from '@/design/tones';
import { inputViolations, screenFixture } from '@/testing/schemas';
import ProfileEditView from './ProfileEditView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), writeNow: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(writeNow);
const settings = screenFixture<SettingsShowOutput>('settings_show');
// Alex: female, 5 ft 6 in, and the last save could not update the targets.
const imperial = screenFixture<SettingsShowOutput>('settings_show.empty');
const warning = screenFixture<SettingsShowOutput>('settings_show.warning');

function render(payload?: SettingsShowOutput) {
    return mount(ProfileEditView, { props: payload ? { payload } : {} });
}

function sent(): Record<string, unknown> {
    const call = writeMock.mock.calls.find(([name]) => name === 'profile_update')!;
    expect(inputViolations('profile_update', call[1])).toEqual([]);
    return call[1] as Record<string, unknown>;
}

async function save(wrapper: ReturnType<typeof render>): Promise<void> {
    await wrapper.get('[data-test="save"]').trigger('click');
    await flushPromises();
}

describe('ProfileEditView', () => {
    beforeEach(() => {
        readMock.mockReset();
        writeMock.mockReset();
    });

    it('SET-004 shows the profile the server holds', () => {
        const wrapper = render(settings);

        expect(wrapper.get<HTMLInputElement>('[data-test="name"]').element.value).toBe('Sam');
        expect(wrapper.get('[data-test="sex-male"]').attributes('aria-pressed')).toBe('true');
        expect(wrapper.get<HTMLInputElement>('[data-test="birth_date"]').element.value).toBe('1990-03-14');
        expect(wrapper.get<HTMLInputElement>('[data-test="height-cm"]').element.value).toBe('178');
        expect(wrapper.get<HTMLSelectElement>('[data-test="body_fat"]').element.value).toBe('low');
        expect(wrapper.get<HTMLInputElement>('[data-test="uses_glp1"]').element.checked).toBe(false);
        expect(wrapper.get<HTMLSelectElement>('[data-test="experience"]').element.value).toBe('intermediate');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('SET-001 asks for feet and inches when the height unit is ft and in', () => {
        const wrapper = render(imperial);

        expect(wrapper.find('[data-test="height-cm"]').exists()).toBe(false);
        expect(wrapper.get<HTMLInputElement>('[data-test="height-feet"]').element.value).toBe('5');
        expect(wrapper.get<HTMLInputElement>('[data-test="height-inches"]').element.value).toBe('6');
    });

    it('SET-004 lists the body-fat buckets for the sex picked, as the server labels them', async () => {
        const wrapper = render(settings);

        expect(wrapper.get('[data-test="body_fat"]').text()).toContain('under 15%');
        await wrapper.get('[data-test="sex-female"]').trigger('click');
        expect(wrapper.get('[data-test="body_fat"]').text()).toContain('under 20%');
    });

    it('SET-004 saves every field as edited', async () => {
        writeMock.mockResolvedValueOnce({ invalidates: ['settings_show'] });
        readMock.mockResolvedValueOnce(settings);
        const wrapper = render(settings);

        await wrapper.get('[data-test="name"]').setValue('  Samantha ');
        await wrapper.get('[data-test="sex-female"]').trigger('click');
        await wrapper.get('[data-test="birth_date"]').setValue('1991-05-02');
        await wrapper.get('[data-test="height-cm"]').setValue('171');
        await wrapper.get('[data-test="body_fat"]').setValue('high');
        await wrapper.get('[data-test="uses_glp1"]').setValue(true);
        await wrapper.get('[data-test="experience"]').setValue('advanced');
        await save(wrapper);

        expect(sent()).toEqual({
            name: 'Samantha',
            sex: 'female',
            birth_date: '1991-05-02',
            height: { unit: 'cm', cm: 171 },
            body_fat_bucket: 'high',
            uses_glp1: true,
            experience: 'advanced',
        });
    });

    it('SET-001 sends a height in feet and inches as typed', async () => {
        writeMock.mockResolvedValueOnce({ invalidates: ['settings_show'] });
        readMock.mockResolvedValueOnce(imperial);
        const wrapper = render(imperial);

        await wrapper.get('[data-test="height-feet"]').setValue('5');
        await wrapper.get('[data-test="height-inches"]').setValue('8.5');
        await save(wrapper);

        expect(sent()).toMatchObject({ height: { unit: 'feetInches', feet: 5, inches: 8.5 } });
    });

    it('SET-004 sends an emptied height as no number, for the server to refuse', async () => {
        writeMock.mockResolvedValueOnce({ invalidates: ['settings_show'] });
        readMock.mockResolvedValueOnce(settings);
        const wrapper = render(settings);

        await wrapper.get('[data-test="height-cm"]').setValue('');
        await save(wrapper);

        expect(sent()).toMatchObject({ height: { unit: 'cm', cm: null } });
    });

    it('SET-004 shows what the save did to the targets, in the server\'s words and tone', async () => {
        writeMock.mockResolvedValueOnce({ invalidates: ['settings_show'] });
        readMock.mockResolvedValueOnce({ ...settings, notice: { text: 'Your targets change from tomorrow.', tone: 'info' } });
        const wrapper = render(settings);

        await save(wrapper);

        expect(wrapper.get('[data-test="notice"]').text()).toBe('Your targets change from tomorrow.');
        expect(wrapper.get('[data-test="notice"]').classes()).toEqual(expect.arrayContaining(toneClasses.info.split(' ')));
    });

    it('CAP-018 shows a save that could not update the targets, and Save stays on to try again', () => {
        const wrapper = render(warning);

        expect(wrapper.get('[data-test="notice"]').text()).toContain('Tap Save to try again.');
        expect(wrapper.get('[data-test="save"]').attributes('disabled')).toBeUndefined();
    });

    it('CAP-012 keeps the edits and says why a save was refused', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('profile_update', 422, { error: { code: 'validation_failed', message: 'Enter a height between 100 and 250 cm.' } }));
        const wrapper = render(settings);

        await wrapper.get('[data-test="height-cm"]').setValue('20');
        await save(wrapper);

        expect(wrapper.get('[role="alert"]').text()).toBe('Enter a height between 100 and 250 cm.');
        expect(wrapper.get<HTMLInputElement>('[data-test="height-cm"]').element.value).toBe('20');
    });

    it('OFF-003 follows the refresh of a cached copy until the user edits the form', async () => {
        let refresh: (data: SettingsShowOutput) => void = () => {};
        readMock.mockImplementationOnce(async (_name, _input, onUpdate) => {
            refresh = onUpdate as typeof refresh;
            return settings;
        });
        writeMock.mockResolvedValueOnce({ invalidates: ['settings_show'] });
        readMock.mockResolvedValueOnce(imperial);
        const wrapper = render();
        await flushPromises();

        // The server's copy is newer: another device moved to ft and in and changed the name.
        refresh(imperial);
        await flushPromises();
        expect(wrapper.get<HTMLInputElement>('[data-test="name"]').element.value).toBe('Alex');
        expect(wrapper.get<HTMLInputElement>('[data-test="height-feet"]').element.value).toBe('5');

        await save(wrapper);
        expect(sent()).toMatchObject({ name: 'Alex', sex: 'female', height: { unit: 'feetInches', feet: 5, inches: 6 }, body_fat_bucket: 'high' });
    });

    it('OFF-003 keeps what the user typed when a refresh lands after it', async () => {
        let refresh: (data: SettingsShowOutput) => void = () => {};
        readMock.mockImplementationOnce(async (_name, _input, onUpdate) => {
            refresh = onUpdate as typeof refresh;
            return settings;
        });
        const wrapper = render();
        await flushPromises();

        await wrapper.get('[data-test="name"]').setValue('Samantha');
        refresh(imperial);
        await flushPromises();

        expect(wrapper.get<HTMLInputElement>('[data-test="name"]').element.value).toBe('Samantha');
        // The height fields stay in the unit the form was filled in.
        expect(wrapper.get<HTMLInputElement>('[data-test="height-cm"]').element.value).toBe('178');
    });

    it('SET-004 shows what the server holds after a save', async () => {
        writeMock.mockResolvedValueOnce({ invalidates: ['settings_show'] });
        readMock.mockResolvedValueOnce({ ...settings, profile: { ...settings.profile, name: 'Samantha' } });
        const wrapper = render(settings);

        await wrapper.get('[data-test="name"]').setValue('  Samantha  ');
        await save(wrapper);

        expect(wrapper.get<HTMLInputElement>('[data-test="name"]').element.value).toBe('Samantha');
    });

    it.each(['5,5', '5.5'])('SET-001 reads inches typed as %s as 5.5', async (typedInches) => {
        writeMock.mockResolvedValueOnce({ invalidates: ['settings_show'] });
        readMock.mockResolvedValueOnce(imperial);
        const wrapper = render(imperial);

        await wrapper.get('[data-test="height-inches"]').setValue(typedInches);
        await save(wrapper);

        expect(sent()).toMatchObject({ height: { unit: 'feetInches', feet: 5, inches: 5.5 } });
    });

    it.each([
        ['height-inches', '5.5.5'],
        ['height-feet', 'five'],
    ])('SET-004 holds Save while %s reads %s, not a number', async (field, text) => {
        const wrapper = render(imperial);

        await wrapper.get(`[data-test="${field}"]`).setValue(text);

        expect(wrapper.get('[data-test="save"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-test="not-number"]').text()).toBe('Type your height as a number.');
        await save(wrapper);
        expect(writeMock).not.toHaveBeenCalled();
    });

    it('PWA-024 shows a skeleton while it loads', () => {
        readMock.mockReturnValueOnce(new Promise(() => {}));
        const wrapper = render();

        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="save"]').exists()).toBe(false);
        expect(readMock).toHaveBeenCalledWith('settings_show', {}, expect.any(Function), expect.any(Function));
    });

    it('CAP-004 says the profile did not load', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('settings_show', 500, null));
        const wrapper = render();
        await flushPromises();

        expect(wrapper.text()).toContain('Your profile could not be loaded.');
    });
});
