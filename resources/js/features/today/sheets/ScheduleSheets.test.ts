import { flushPromises, type VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, readFresh, write } from '@/app/call';
import type { TodayShowOutput } from '@/contracts/today_show';
import BusyPeriodSheet from './BusyPeriodSheet.vue';
import EditScheduleSheet from './EditScheduleSheet.vue';
import { refusal, sent, sheet, today } from './sheetTesting';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), write: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(write);
const day = today();
const schedule = day.schedule!;

beforeEach(() => {
    readMock.mockReset().mockResolvedValue(day);
    vi.mocked(readFresh).mockReset().mockResolvedValue(day);
    writeMock.mockReset().mockResolvedValue({});
});

const pressed = (wrapper: VueWrapper) => wrapper.findAll('[aria-pressed="true"]').map((chip) => chip.attributes('aria-label'));
const chip = (wrapper: VueWrapper, label: string) => wrapper.find(`button[aria-label="${label}"]`);
const save = async (wrapper: VueWrapper) => {
    await wrapper.find('[data-save]').trigger('click');
    await flushPromises();
};

describe('BusyPeriodSheet', () => {
    it('SCH-033 opens on 9 to 10 AM, repeating on the day\'s weekday, and needs a label', () => {
        const wrapper = sheet(BusyPeriodSheet, { payload: day });
        const times = wrapper.findAll<HTMLInputElement>('input[type="time"]').map((input) => input.element.value);

        expect(wrapper.text()).toContain('Add busy period');
        expect(times).toEqual(['09:00', '10:00']);
        expect(pressed(wrapper)).toEqual(['Wed']);
        expect(wrapper.text()).toContain('Repeats on');
        expect(wrapper.text()).toContain('Applies from tomorrow. To change today, use Edit schedule for this day.');
        expect(wrapper.find('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('SCH-033 saves the label, times and weekdays as a valid busy_period_set', async () => {
        const wrapper = sheet(BusyPeriodSheet, { payload: day });

        await wrapper.find('input[type="text"]').setValue(' School run ');
        await wrapper.findAll('input[type="time"]')[0]!.setValue('08:15');
        await wrapper.findAll('input[type="time"]')[1]!.setValue('08:45');
        await chip(wrapper, 'Fri').trigger('click');
        await chip(wrapper, 'Mon').trigger('click');
        await save(wrapper);

        expect(sent()).toEqual([
            ['busy_period_set', { id: expect.stringMatching(/^[0-9A-HJKMNP-TV-Z]{26}$/), label: 'School run', start: '08:15', end: '08:45', weekdays: [1, 3, 5] }],
        ]);
        expect(wrapper.emitted('close')).toEqual([[true]]);
    });

    it('SCH-033 needs a weekday and a period that is not empty', async () => {
        const wrapper = sheet(BusyPeriodSheet, { payload: day });
        await wrapper.find('input[type="text"]').setValue('Gym class');

        await chip(wrapper, 'Wed').trigger('click');
        expect(pressed(wrapper)).toEqual([]);
        expect(wrapper.find('[data-save]').attributes('disabled')).toBeDefined();

        await chip(wrapper, 'Wed').trigger('click');
        await wrapper.findAll('input[type="time"]')[1]!.setValue('09:00');
        expect(wrapper.find('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('CAP-012 says why the period was not saved and stays open', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('busy_period_set', 422, refusal('That period overlaps your eating window.', 'start')));
        const wrapper = sheet(BusyPeriodSheet, { payload: day });

        await wrapper.find('input[type="text"]').setValue('Meeting');
        await save(wrapper);

        expect(wrapper.find('[role="alert"]').text()).toBe('That period overlaps your eating window.');
        expect(wrapper.emitted('close')).toBeUndefined();
    });

    it('SCH-033 repeats on a Saturday from a Saturday (today_show.empty)', () => {
        const wrapper = sheet(BusyPeriodSheet, { payload: today('empty') });

        expect(pressed(wrapper)).toEqual(['Sat']);
    });

    it('OFF-008 says the day is waiting to sync (today_show.warning)', () => {
        const wrapper = sheet(BusyPeriodSheet, { payload: today('warning'), pending: true });

        expect(wrapper.text()).toContain('Waiting to sync');
    });

    it('SCH-033 has no schedule to add to before the profile exists', () => {
        const wrapper = sheet(BusyPeriodSheet, { payload: { ...day, schedule: null } });

        expect(wrapper.text()).toContain('There is no schedule to change yet.');
        expect(wrapper.find('input[type="text"]').exists()).toBe(false);
    });

    it('CAP-002 reads the day with a skeleton, then says why it failed', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('today_show', 500, null));
        const wrapper = sheet(BusyPeriodSheet, { date: '2026-09-23' });
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        await flushPromises();

        expect(wrapper.text()).toContain('This day could not be loaded.');
    });
});

describe('EditScheduleSheet', () => {
    const allFuture = async (wrapper: VueWrapper) => wrapper.findAll('[role="radio"]').find((radio) => radio.text() === 'All future days')!.trigger('click');
    const fewerDays = () => new CapabilityError('training_weekdays_change', 422, refusal('Pick what your mesocycle does with fewer days.', 'fewer_days'));
    const scheduleInput = { date: '2026-09-23', window_start: '08:00', window_end: '20:00', meal_count: 4, busy_periods: [{ label: 'Team meeting', start: '09:00', end: '10:30' }] };

    it('SCH-037 opens on this day: its eating window, meals, session time and busy periods', () => {
        const wrapper = sheet(EditScheduleSheet, { payload: day });
        const times = wrapper.findAll<HTMLInputElement>('input[type="time"]').map((input) => input.element.value);

        expect(wrapper.text()).toContain('Edit schedule');
        expect(wrapper.text()).toContain('Wednesday, Sep 23');
        expect(wrapper.find('[role="radio"][aria-checked="true"]').text()).toBe('This day');
        expect(wrapper.text()).toContain('Only this day changes. Your schedule stays as it is.');
        expect(wrapper.text()).toContain('Meals a day: 4');
        expect(times).toEqual(['08:00', '20:00', '17:30', '09:00', '10:30']);
        expect(wrapper.find<HTMLInputElement>('input[aria-label="Busy period label"]').element.value).toBe('Team meeting');
        expect(wrapper.find('[role="switch"]').exists()).toBe(false);
    });

    it('SCH-037 saves this day\'s edits as a valid schedule_update', async () => {
        const wrapper = sheet(EditScheduleSheet, { payload: day });

        await wrapper.findAll('input[type="time"]')[1]!.setValue('21:00');
        await wrapper.find('[aria-label="More: Meals a day"]').trigger('click');
        await wrapper.find('[aria-label="Remove busy period"]').trigger('click');
        await wrapper.find('[data-test="add-busy"]').trigger('click');
        await save(wrapper);

        expect(sent()).toEqual([
            [
                'schedule_update',
                { ...scheduleInput, apply_to: 'this_day', window_end: '21:00', meal_count: 5, busy_periods: [{ label: 'Busy', start: '09:00', end: '10:00' }], training_start: '17:30' },
            ],
        ]);
        expect(wrapper.emitted('close')).toEqual([[true]]);
    });

    it('SCH-037 sends no session time for a day without one', async () => {
        const rest = { ...day, schedule: { ...schedule, this_day: { ...schedule.this_day, training_start: null } } };
        const wrapper = sheet(EditScheduleSheet, { payload: rest });

        expect(wrapper.text()).not.toContain('Training');
        await save(wrapper);

        expect(sent()).toEqual([['schedule_update', { ...scheduleInput, apply_to: 'this_day', training_start: null }]]);
    });

    it('SCH-037 needs an eating window and labelled busy periods', async () => {
        const wrapper = sheet(EditScheduleSheet, { payload: day });

        await wrapper.find('input[aria-label="Busy period label"]').setValue(' ');
        expect(wrapper.find('[data-save]').attributes('disabled')).toBeDefined();

        await wrapper.find('input[aria-label="Busy period label"]').setValue('Meeting');
        await wrapper.findAll('input[type="time"]')[1]!.setValue('08:00');
        expect(wrapper.find('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('SCH-037 all future days: the weekday\'s schedule, whether it trains, and what changes when', async () => {
        const wrapper = sheet(EditScheduleSheet, { payload: day });

        await allFuture(wrapper);

        expect(wrapper.text()).toContain('Training time and new busy periods apply to Wednesdays only.');
        expect(wrapper.find('[role="switch"]').attributes('aria-checked')).toBe('true');
        expect(wrapper.text()).toContain('Train on Wednesdays');
        await save(wrapper);

        expect(sent()).toEqual([['schedule_update', { ...scheduleInput, apply_to: 'all_future_days', training_start: '17:30' }]]);
    });

    it('SCH-039 makes the weekday a rest day with a valid training_weekdays_change, then saves the schedule', async () => {
        const wrapper = sheet(EditScheduleSheet, { payload: day });

        await allFuture(wrapper);
        await wrapper.find('[role="switch"]').trigger('click');
        expect(wrapper.findAll('input[type="time"]')).toHaveLength(4);
        await save(wrapper);

        expect(sent()).toEqual([
            ['training_weekdays_change', { weekdays: [1, 5], fewer_days: null }],
            ['schedule_update', { ...scheduleInput, apply_to: 'all_future_days', training_start: null }],
        ]);
        expect(wrapper.emitted('close')).toEqual([[true]]);
    });

    it('SCH-039 asks what the mesocycle does with fewer days, and sends the choice', async () => {
        writeMock.mockRejectedValueOnce(fewerDays());
        const wrapper = sheet(EditScheduleSheet, { payload: day });

        await allFuture(wrapper);
        await wrapper.find('[role="switch"]').trigger('click');
        await save(wrapper);

        expect(wrapper.text()).toContain('Training days changed');
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
        await wrapper.findAll('[role="radio"]').find((radio) => radio.text().includes('Keep all sessions'))!.trigger('click');
        await flushPromises();

        expect(sent()).toEqual([
            ['training_weekdays_change', { weekdays: [1, 5], fewer_days: null }],
            ['training_weekdays_change', { weekdays: [1, 5], fewer_days: 'extend' }],
            ['schedule_update', { ...scheduleInput, apply_to: 'all_future_days', training_start: null }],
        ]);
        expect(wrapper.emitted('close')).toEqual([[true]]);
    });

    it('SCH-039 Cancel on the choice saves nothing', async () => {
        writeMock.mockRejectedValueOnce(fewerDays());
        const wrapper = sheet(EditScheduleSheet, { payload: day });

        await allFuture(wrapper);
        await wrapper.find('[role="switch"]').trigger('click');
        await save(wrapper);
        const cancels = wrapper.findAll('[data-cancel]');
        await cancels.at(-1)!.trigger('click');

        expect(wrapper.text()).not.toContain('Training days changed');
        expect(writeMock).toHaveBeenCalledTimes(1);
        expect(wrapper.emitted('close')).toBeUndefined();
    });

    it('SCH-039 does not change the weekdays again when only the schedule failed', async () => {
        writeMock.mockResolvedValueOnce({}).mockRejectedValueOnce(new CapabilityError('schedule_update', 422, refusal('The eating window is too short.', 'window_end')));
        const wrapper = sheet(EditScheduleSheet, { payload: day });

        await allFuture(wrapper);
        await wrapper.find('[role="switch"]').trigger('click');
        await save(wrapper);
        expect(wrapper.find('[role="alert"]').text()).toBe('The eating window is too short.');
        await save(wrapper);

        expect(sent().map(([name]) => name)).toEqual(['training_weekdays_change', 'schedule_update', 'schedule_update']);
    });

    it('SCH-039 says why the weekdays were not changed', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('training_weekdays_change', 422, refusal('Pick at least one training day.', 'weekdays')));
        const wrapper = sheet(EditScheduleSheet, { payload: day });

        await allFuture(wrapper);
        await wrapper.find('[role="switch"]').trigger('click');
        await save(wrapper);

        expect(wrapper.find('[role="alert"]').text()).toBe('Pick at least one training day.');
        expect(writeMock).toHaveBeenCalledTimes(1);
    });

    it('SCH-037 makes a rest weekday a training day from all future days (today_show.empty)', async () => {
        const wrapper = sheet(EditScheduleSheet, { payload: today('empty') });

        await allFuture(wrapper);
        expect(wrapper.text()).toContain('Train on Saturdays');
        expect(wrapper.find('[role="switch"]').attributes('aria-checked')).toBe('false');
        await wrapper.find('[role="switch"]').trigger('click');
        await save(wrapper);

        expect(sent()[0]).toEqual(['training_weekdays_change', { weekdays: [1, 3, 5, 6], fewer_days: null }]);
    });

    it('SCH-037 keeps this day\'s and all future days\' edits apart, and saves the one shown', async () => {
        const future = { ...schedule.all_future_days, window_start: '07:00', window_end: '22:00', meal_count: 3, busy_periods: [] };
        const wrapper = sheet(EditScheduleSheet, { payload: { ...day, schedule: { ...schedule, all_future_days: future } } });

        await wrapper.findAll('input[type="time"]')[1]!.setValue('21:00');
        await allFuture(wrapper);
        expect(wrapper.findAll<HTMLInputElement>('input[type="time"]').map((input) => input.element.value)).toEqual(['07:00', '22:00', '17:30']);
        expect(wrapper.text()).toContain('Meals a day: 3');
        await save(wrapper);

        expect(sent()).toEqual([['schedule_update', { date: '2026-09-23', apply_to: 'all_future_days', window_start: '07:00', window_end: '22:00', meal_count: 3, busy_periods: [], training_start: '17:30' }]]);
    });

    it('SCH-039 asks for a start time when a rest weekday becomes a training day', async () => {
        const rest = { ...schedule.all_future_days, training_start: null };
        const weekdays = schedule.training_weekdays.map((weekday) => ({ ...weekday, selected: weekday.iso === 1 }));
        const wrapper = sheet(EditScheduleSheet, { payload: { ...day, schedule: { ...schedule, all_future_days: rest, training_weekdays: weekdays } } });

        await allFuture(wrapper);
        await wrapper.find('[role="switch"]').trigger('click');
        expect(wrapper.findAll<HTMLInputElement>('input[type="time"]')[2]!.element.value).toBe('');
        expect(wrapper.find('[data-save]').attributes('disabled')).toBeDefined();

        await wrapper.findAll('input[type="time"]')[2]!.setValue('06:30');
        await save(wrapper);

        expect(sent()).toEqual([
            ['training_weekdays_change', { weekdays: [1, 3], fewer_days: null }],
            ['schedule_update', { ...scheduleInput, apply_to: 'all_future_days', training_start: '06:30' }],
        ]);
    });

    it('SCH-039 says the sheet saved when it closes after the weekdays changed but the schedule did not', async () => {
        writeMock.mockResolvedValueOnce({}).mockRejectedValueOnce(new CapabilityError('schedule_update', 422, refusal('The eating window is too short.', 'window_end')));
        const wrapper = sheet(EditScheduleSheet, { payload: day });

        await allFuture(wrapper);
        await wrapper.find('[role="switch"]').trigger('click');
        await save(wrapper);
        await wrapper.find('[data-cancel]').trigger('click');

        expect(wrapper.emitted('close')).toEqual([[true]]);
    });

    it('SCH-039 keeps the chosen weekday change when the schedule save after it fails', async () => {
        writeMock
            .mockRejectedValueOnce(fewerDays())
            .mockResolvedValueOnce({})
            .mockRejectedValueOnce(new CapabilityError('schedule_update', 422, refusal('The eating window is too short.', 'window_end')));
        const wrapper = sheet(EditScheduleSheet, { payload: day });

        await allFuture(wrapper);
        await wrapper.find('[role="switch"]').trigger('click');
        await save(wrapper);
        await wrapper.findAll('[role="radio"]').find((radio) => radio.text().includes('Keep all sessions'))!.trigger('click');
        await flushPromises();
        await save(wrapper);

        expect(sent().map(([name]) => name)).toEqual(['training_weekdays_change', 'training_weekdays_change', 'schedule_update', 'schedule_update']);
    });

    it('CAP-012 shows the schedule\'s own refusal after an earlier training-days refusal', async () => {
        writeMock
            .mockRejectedValueOnce(new CapabilityError('training_weekdays_change', 422, refusal('Pick at least one training day.', 'weekdays')))
            .mockRejectedValueOnce(new CapabilityError('schedule_update', 422, refusal('The eating window is too short.', 'window_end')));
        const wrapper = sheet(EditScheduleSheet, { payload: day });

        await allFuture(wrapper);
        await wrapper.find('[role="switch"]').trigger('click');
        await save(wrapper);
        await wrapper.find('[role="switch"]').trigger('click');
        await save(wrapper);

        expect(wrapper.find('[role="alert"]').text()).toBe('The eating window is too short.');
    });

    it('SCH-039 shows what the server says the change does to the mesocycle', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('training_weekdays_change', 422, refusal('Your mesocycle runs 6 weeks instead of 4 if you keep all sessions.', 'fewer_days')));
        const wrapper = sheet(EditScheduleSheet, { payload: day });

        await allFuture(wrapper);
        await wrapper.find('[role="switch"]').trigger('click');
        await save(wrapper);

        expect(wrapper.text()).toContain('Your mesocycle runs 6 weeks instead of 4 if you keep all sessions.');
    });

    it('NUT-024 offers only all future days on a past day', () => {
        const past: TodayShowOutput = { ...day, schedule: { ...schedule, apply_to: [{ value: 'all_future_days', label: 'All future days' }] } };
        const wrapper = sheet(EditScheduleSheet, { payload: past });

        expect(wrapper.findAll('[role="radio"]').map((radio) => radio.text())).toEqual(['All future days']);
        expect(wrapper.find('[role="switch"]').exists()).toBe(true);
    });

    it('OFF-008 says the day is waiting to sync (today_show.warning)', () => {
        const wrapper = sheet(EditScheduleSheet, { payload: today('warning'), pending: true });

        expect(wrapper.text()).toContain('Waiting to sync');
    });

    it('SCH-037 has no schedule to edit before the profile exists', () => {
        const wrapper = sheet(EditScheduleSheet, { payload: { ...day, schedule: null } });

        expect(wrapper.text()).toContain('There is no schedule to change yet.');
        expect(wrapper.find('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('CAP-002 reads the day with a skeleton, then says why it failed', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('today_show', 500, null));
        const wrapper = sheet(EditScheduleSheet, { date: '2026-09-23' });
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        await flushPromises();

        expect(wrapper.text()).toContain('This day could not be loaded.');
    });
});
