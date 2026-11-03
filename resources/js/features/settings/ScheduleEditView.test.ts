import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, writeNow } from '@/app/call';
import type { SettingsShowOutput } from '@/contracts/settings_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import ScheduleEditView from './ScheduleEditView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), writeNow: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(writeNow);
const settings = screenFixture<SettingsShowOutput>('settings_show');
// No busy periods, no step plan and no plan yet, so no review day.
const empty = screenFixture<SettingsShowOutput>('settings_show.empty');
const warning = screenFixture<SettingsShowOutput>('settings_show.warning');

function render(payload?: SettingsShowOutput) {
    return mount(ScheduleEditView, { props: payload ? { payload } : {} });
}

function sent(): Record<string, unknown> {
    const call = writeMock.mock.calls.find(([name]) => name === 'schedule_defaults_update')!;
    expect(inputViolations('schedule_defaults_update', call[1])).toEqual([]);
    return call[1] as Record<string, unknown>;
}

async function save(wrapper: ReturnType<typeof render>): Promise<void> {
    await wrapper.get('[data-test="save"]').trigger('click');
    await flushPromises();
}

const saved = () => {
    writeMock.mockResolvedValueOnce({ invalidates: ['settings_show'] });
    readMock.mockResolvedValueOnce(settings);
};

describe('ScheduleEditView', () => {
    beforeEach(() => {
        readMock.mockReset();
        writeMock.mockReset();
    });

    it('SET-005 shows the schedule the server holds', () => {
        const wrapper = render(settings);

        expect(wrapper.get<HTMLInputElement>('[data-test="window-start"]').element.value).toBe('07:00');
        expect(wrapper.get<HTMLInputElement>('[data-test="window-end"]').element.value).toBe('21:00');
        expect(wrapper.text()).toContain('Meals a day: 4');
        expect(wrapper.get<HTMLInputElement>('[data-test="training-1"]').element.checked).toBe(true);
        expect(wrapper.get<HTMLInputElement>('[data-test="training-2"]').element.checked).toBe(false);
        expect(wrapper.get<HTMLInputElement>('[data-test="training-start-1"]').element.value).toBe('17:30');
        expect(wrapper.text()).toContain('Length: 60 min');
        expect(wrapper.get<HTMLInputElement>('[data-test="busy-label-0"]').element.value).toBe('Work meeting');
        expect(wrapper.get<HTMLInputElement>('[data-test="steps-min-1"]').element.value).toBe('7000');
        expect(wrapper.get<HTMLSelectElement>('[data-test="review-day"]').element.value).toBe('1');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('SET-005 saves the schedule unchanged, in the shape the server takes', async () => {
        saved();
        const wrapper = render(settings);

        await save(wrapper);

        const input = sent();
        expect(input).toMatchObject({ eating_window_start: '07:00', eating_window_end: '21:00', meal_count: 4, review_weekday: 1 });
        expect(input.training_days).toEqual(settings.schedule.training_days.map(({ weekday, start, minutes }) => ({ weekday, start, minutes })));
        expect(input.busy_periods).toEqual([{ label: 'Work meeting', start: '09:00', end: '10:30', weekdays: [1, 2, 3, 4, 5].map((weekday) => ({ weekday })) }]);
        expect((input.step_plan as unknown[]).length).toBe(7);
        expect((input.step_plan as unknown[])[0]).toEqual({ weekday: 1, min: 7000, max: 9000 });
    });

    it('SET-005 sends the eating window and meal count as changed', async () => {
        saved();
        const wrapper = render(settings);

        await wrapper.get('[data-test="window-start"]').setValue('06:30');
        await wrapper.get('[data-test="window-end"]').setValue('20:00');
        await wrapper.get('[aria-label="More: Meals a day"]').trigger('click');
        await save(wrapper);

        expect(sent()).toMatchObject({ eating_window_start: '06:30', eating_window_end: '20:00', meal_count: 5 });
    });

    it('SET-005 adds a training day at the server\'s default time and length, and removes one', async () => {
        saved();
        const wrapper = render({ ...settings, schedule: { ...settings.schedule, new_training_day_start: '06:15', new_training_day_minutes: 45 } });

        await wrapper.get('[data-test="training-2"]').setValue(true);
        expect(wrapper.get<HTMLInputElement>('[data-test="training-start-2"]').element.value).toBe('06:15');
        expect(wrapper.text()).toContain('Length: 45 min');
        await wrapper.get('[data-test="training-1"]').setValue(false);
        expect(wrapper.find('[data-test="training-start-1"]').exists()).toBe(false);
        await wrapper.get('[data-test="training-start-2"]').setValue('06:00');
        await wrapper.get('[aria-label="More: Tuesday length"]').trigger('click');
        await save(wrapper);

        const days = sent().training_days as { weekday: number }[];
        expect(days.map((day) => day.weekday)).not.toContain(1);
        expect(days.find((day) => day.weekday === 2)).toEqual({ weekday: 2, start: '06:00', minutes: 50 });
    });

    it('SET-005 adds a busy period from the server\'s template, edits it and removes the old one', async () => {
        saved();
        const wrapper = render(settings);

        await wrapper.get('[data-test="add-busy"]').trigger('click');
        await wrapper.get('[data-test="busy-label-1"]').setValue('School run');
        await wrapper.get('[data-test="busy-end-1"]').setValue('09:30');
        await wrapper.get('[data-test="busy-days-1"] [aria-label="Saturday"]').trigger('click');
        await wrapper.get('[data-test="busy-remove-0"]').trigger('click');
        await save(wrapper);

        const onDays = settings.schedule.new_busy_period.days.filter((day) => day.on).map((day) => day.weekday);
        expect(sent().busy_periods).toEqual([
            {
                label: 'School run',
                start: settings.schedule.new_busy_period.start,
                end: '09:30',
                weekdays: [...onDays, 6].map((weekday) => ({ weekday })),
            },
        ]);
    });

    it('SET-005 leaves a day with an empty step range out of the step plan', async () => {
        saved();
        const wrapper = render(settings);

        await wrapper.get('[data-test="steps-min-1"]').setValue('');
        await wrapper.get('[data-test="steps-max-1"]').setValue('');
        await wrapper.get('[data-test="steps-max-2"]').setValue('12000');
        await save(wrapper);

        const plan = sent().step_plan as { weekday: number; max: number }[];
        expect(plan.map((day) => day.weekday)).not.toContain(1);
        expect(plan.find((day) => day.weekday === 2)!.max).toBe(12000);
    });

    it.each(['8.000', '8,000', '8k', '-5'])('SET-005 sends a step count typed as %s as no number, for the server to refuse', async (typed) => {
        writeMock.mockRejectedValueOnce(new CapabilityError('schedule_defaults_update', 422, { error: { code: 'validation_failed', message: 'Enter whole steps.' } }));
        const wrapper = render(settings);

        await wrapper.get('[data-test="steps-min-1"]').setValue(typed);
        await save(wrapper);

        const call = writeMock.mock.calls.find(([name]) => name === 'schedule_defaults_update')!;
        expect((call[1].step_plan as { weekday: number; min: unknown }[])[0]).toEqual({ weekday: 1, min: null, max: 9000 });
        expect(wrapper.get('[role="alert"]').text()).toBe('Enter whole steps.');
    });

    it('OFF-003 follows the refresh of a cached copy until the user edits the form', async () => {
        let refresh: (data: SettingsShowOutput) => void = () => {};
        readMock.mockImplementationOnce(async (_name, _input, onUpdate) => {
            refresh = onUpdate as typeof refresh;
            return settings;
        });
        writeMock.mockResolvedValueOnce({ invalidates: ['settings_show'] });
        readMock.mockResolvedValueOnce(empty);
        const wrapper = render();
        await flushPromises();

        refresh({ ...empty, schedule: { ...empty.schedule, meal_count: 5 } });
        await flushPromises();
        expect(wrapper.text()).toContain('Meals a day: 5');

        await save(wrapper);
        expect(sent()).toMatchObject({ meal_count: 5, training_days: [], busy_periods: [], review_weekday: null });
    });

    it('OFF-003 keeps what the user changed when a refresh lands after it', async () => {
        let refresh: (data: SettingsShowOutput) => void = () => {};
        readMock.mockImplementationOnce(async (_name, _input, onUpdate) => {
            refresh = onUpdate as typeof refresh;
            return settings;
        });
        const wrapper = render();
        await flushPromises();

        await wrapper.get('[aria-label="More: Meals a day"]').trigger('click');
        refresh(empty);
        await flushPromises();

        expect(wrapper.text()).toContain('Meals a day: 5');
        expect(wrapper.find('[data-test="busy-label-0"]').exists()).toBe(true);
    });

    it('SET-005 holds Save while a day has only one end of its step range', async () => {
        const wrapper = render(empty);

        await wrapper.get('[data-test="steps-min-3"]').setValue('5000');

        expect(wrapper.get('[data-test="save"]').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('Fill in both numbers for a day, or leave both empty.');
        await wrapper.get('[data-test="steps-max-3"]').setValue('8000');
        expect(wrapper.get('[data-test="save"]').attributes('disabled')).toBeUndefined();
    });

    it('SET-005 sends a new review day', async () => {
        saved();
        const wrapper = render(settings);

        await wrapper.get('[data-test="review-day"]').setValue('4');
        await save(wrapper);

        expect(sent()).toMatchObject({ review_weekday: 4 });
    });

    it('SET-005 offers no review day before there is a plan, and leaves it as it is', async () => {
        saved();
        const wrapper = render(empty);

        expect(wrapper.find('[data-test="review-day"]').exists()).toBe(false);
        await save(wrapper);

        expect(sent()).toMatchObject({ busy_periods: [], step_plan: [], review_weekday: null });
    });

    it('CAP-018 shows what the last save did, and keeps Save on to try again', () => {
        const wrapper = render(warning);

        expect(wrapper.get('[data-test="notice"]').text()).toContain('Tap Save to try again.');
        expect(wrapper.get('[data-test="save"]').attributes('disabled')).toBeUndefined();
    });

    it('CAP-012 keeps the edits and says why a save was refused', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('schedule_defaults_update', 422, { error: { code: 'validation_failed', message: 'The window must end after it starts.' } }));
        const wrapper = render(settings);

        await wrapper.get('[data-test="window-end"]').setValue('05:00');
        await save(wrapper);

        expect(wrapper.get('[role="alert"]').text()).toBe('The window must end after it starts.');
        expect(wrapper.get<HTMLInputElement>('[data-test="window-end"]').element.value).toBe('05:00');
    });

    it('PWA-024 shows a skeleton while it loads', () => {
        readMock.mockReturnValueOnce(new Promise(() => {}));
        const wrapper = render();

        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
    });

    it('CAP-004 says the schedule did not load', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('settings_show', 500, null));
        const wrapper = render();
        await flushPromises();

        expect(wrapper.text()).toContain('Your schedule could not be loaded.');
    });
});
