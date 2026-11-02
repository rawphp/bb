import { flushPromises } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, readFresh, write } from '@/app/call';
import { refusal, sent, sheet, today, week } from './sheetTesting';
import TodayAddMealSheet from './TodayAddMealSheet.vue';
import TodayCalendarSheet from './TodayCalendarSheet.vue';
import TodayMoveDaySheet from './TodayMoveDaySheet.vue';
import TodayWorkoutTimeSheet from './TodayWorkoutTimeSheet.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), write: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(write);
const day = today();
const workout = day.workouts[0]!;

beforeEach(() => {
    readMock.mockReset().mockResolvedValue(day);
    vi.mocked(readFresh).mockReset().mockResolvedValue(day);
    writeMock.mockReset().mockResolvedValue({});
});

describe('TodayCalendarSheet', () => {
    it('SCH-023 opens a date picker on the selected day, held to the plan\'s dates', () => {
        const wrapper = sheet(TodayCalendarSheet, { payload: week() });
        const input = wrapper.find<HTMLInputElement>('input[type="date"]');

        expect(wrapper.text()).toContain('Jump to date');
        expect(input.element.value).toBe('2026-09-23');
        expect(input.attributes('min')).toBe('2026-09-05');
        expect(input.attributes('max')).toBe('2026-12-18');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('SCH-023 Go hands the picked date to the screen and writes nothing', async () => {
        const wrapper = sheet(TodayCalendarSheet, { payload: week() });

        await wrapper.find('input[type="date"]').setValue('2026-10-14');
        await wrapper.find('[data-save]').trigger('click');

        expect(wrapper.emitted('pick')).toEqual([['2026-10-14']]);
        expect(writeMock).not.toHaveBeenCalled();
    });

    it('SCH-023 has no dates to offer before the plan has any (week_show.empty)', () => {
        const wrapper = sheet(TodayCalendarSheet, { payload: week('empty') });

        expect(wrapper.find('input[type="date"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Your plan has no dates yet.');
        expect(wrapper.find('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('SCH-023 shows the same picker with a week warning (week_show.warning)', () => {
        const wrapper = sheet(TodayCalendarSheet, { payload: week('warning') });

        expect(wrapper.find('input[type="date"]').attributes('max')).toBe('2026-12-18');
    });

    it('CAP-002 reads the week of the date it opens on, with a skeleton, then an error with Try again', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('week_show', 500, null)).mockResolvedValueOnce(week());
        const wrapper = sheet(TodayCalendarSheet, { date: '2026-09-23' });
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('week_show', { date: '2026-09-23' }, expect.any(Function), expect.any(Function));
        expect(wrapper.text()).toContain('The calendar could not be loaded.');
        await wrapper.find('[data-test="retry"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('input[type="date"]').exists()).toBe(true);
    });

    it('UI-005 Cancel closes it', async () => {
        const wrapper = sheet(TodayCalendarSheet, { payload: week() });

        await wrapper.find('[data-cancel]').trigger('click');

        expect(wrapper.emitted('close')).toEqual([[false]]);
    });
});

describe('TodayWorkoutTimeSheet', () => {
    it('SCH-050 opens on the workout\'s start time', () => {
        const wrapper = sheet(TodayWorkoutTimeSheet, { payload: day });

        expect(wrapper.text()).toContain('Change time');
        expect(wrapper.find<HTMLInputElement>('input[type="time"]').element.value).toBe('17:30');
    });

    it('SCH-050 saves the new start with a valid workout_time_set and closes', async () => {
        const wrapper = sheet(TodayWorkoutTimeSheet, { payload: day });

        await wrapper.find('input[type="time"]').setValue('18:15');
        await wrapper.find('[data-save]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['workout_time_set', { mesocycle_id: workout.mesocycle_id, week: workout.week, day: workout.day, time: '18:15' }]]);
        expect(readMock).toHaveBeenCalledWith('today_show', { date: day.date }, expect.any(Function), expect.any(Function));
        expect(wrapper.emitted('close')).toEqual([[true]]);
    });

    it('CAP-012 shows why the time was not changed and stays open', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('workout_time_set', 422, refusal('This workout has started.', 'time')));
        const wrapper = sheet(TodayWorkoutTimeSheet, { payload: day });

        await wrapper.find('[data-save]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toBe('This workout has started.');
        expect(wrapper.emitted('close')).toBeUndefined();
    });

    it('SCH-050 has nothing to change on a day without a workout (today_show.empty)', () => {
        const wrapper = sheet(TodayWorkoutTimeSheet, { payload: today('empty') });

        expect(wrapper.text()).toContain('This day has no workout.');
        expect(wrapper.find('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('SCH-050 changes a finished workout\'s time the same way (today_show.warning)', () => {
        const wrapper = sheet(TodayWorkoutTimeSheet, { payload: today('warning') });

        expect(wrapper.find<HTMLInputElement>('input[type="time"]').element.value).toBe(today('warning').workouts[0]!.time);
    });

    it('OFF-008 says the day is waiting to sync', () => {
        const wrapper = sheet(TodayWorkoutTimeSheet, { payload: day, pending: true });

        expect(wrapper.text()).toContain('Waiting to sync');
    });

    it('CAP-002 reads the day with a skeleton, then says why it failed', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('today_show', 500, null));
        const wrapper = sheet(TodayWorkoutTimeSheet, { date: '2026-09-23' });
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        expect(wrapper.find('[data-save]').attributes('disabled')).toBeDefined();
        await flushPromises();

        expect(wrapper.text()).toContain('This day could not be loaded.');
    });
});

describe('TodayAddMealSheet', () => {
    it('SCH-031 opens on the day and the time the server suggests', () => {
        const wrapper = sheet(TodayAddMealSheet, { payload: day });

        expect(wrapper.text()).toContain('Add meal');
        expect(wrapper.text()).toContain('Wednesday, Sep 23');
        expect(wrapper.text()).toContain('The day\'s macros are shared across the meals you haven\'t checked in.');
        expect(wrapper.find<HTMLInputElement>('input[type="time"]').element.value).toBe('14:00');
    });

    it('SCH-031 adds a meal at the picked time with a valid meal_add', async () => {
        const wrapper = sheet(TodayAddMealSheet, { payload: day });

        await wrapper.find('input[type="time"]').setValue('15:30');
        await wrapper.find('[data-save]').trigger('click');
        await flushPromises();

        const [name, input] = sent()[0]!;
        expect(name).toBe('meal_add');
        expect(input).toEqual({ id: expect.stringMatching(/^[0-9A-HJKMNP-TV-Z]{26}$/), date: '2026-09-23', time: '15:30' });
        expect(wrapper.emitted('close')).toEqual([[true]]);
    });

    it('CAP-019 sends the same meal id again when the first save got no answer', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('meal_add', 0, { reason: 'offline' }));
        const wrapper = sheet(TodayAddMealSheet, { payload: day });

        await wrapper.find('[data-save]').trigger('click');
        await flushPromises();
        await wrapper.find('[data-save]').trigger('click');
        await flushPromises();

        const ids = sent().map(([, input]) => input.id);
        expect(ids).toHaveLength(2);
        expect(ids[1]).toBe(ids[0]);
    });

    it('SCH-031 offers no time on a past day', () => {
        const wrapper = sheet(TodayAddMealSheet, { payload: { ...day, add_meal_time: null } });

        expect(wrapper.find('input[type="time"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('A meal can\'t be added to a past day.');
        expect(wrapper.find('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('SCH-031 adds a meal before the plan starts (today_show.empty)', () => {
        const wrapper = sheet(TodayAddMealSheet, { payload: today('empty') });

        expect(wrapper.text()).toContain('Saturday, Sep 19');
        expect(wrapper.find('[data-save]').attributes('disabled')).toBeUndefined();
    });

    it('OFF-008 says the day is waiting to sync', () => {
        const wrapper = sheet(TodayAddMealSheet, { payload: today('warning'), pending: true });

        expect(wrapper.text()).toContain('Waiting to sync');
    });

    it('CAP-002 reads the day with a skeleton, then says why it failed', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('today_show', 500, null));
        const wrapper = sheet(TodayAddMealSheet, {});
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('today_show', {}, expect.any(Function), expect.any(Function));
        expect(wrapper.text()).toContain('This day could not be loaded.');
    });
});

describe('TodayMoveDaySheet', () => {
    it('SCH-051 lists the days of the plan week, with why a day is not offered', () => {
        const wrapper = sheet(TodayMoveDaySheet, { payload: day });
        const rows = wrapper.findAll('[role="radio"]');

        expect(wrapper.text()).toContain('Move to another day');
        expect(rows.map((row) => row.attributes('aria-label'))).toEqual(['Thu, Sep 24', 'Fri, Sep 25']);
        expect(rows[1]!.attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('This day already has a workout. Pick a different day.');
        expect(wrapper.find('[data-save]').exists()).toBe(false);
    });

    it('SCH-051 moves the workout with a valid workout_move on a tap', async () => {
        const wrapper = sheet(TodayMoveDaySheet, { payload: day });

        await wrapper.findAll('[role="radio"]')[0]!.trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['workout_move', { mesocycle_id: workout.mesocycle_id, week: workout.week, day: workout.day, date: '2026-09-24' }]]);
        expect(wrapper.emitted('close')).toEqual([[true]]);
    });

    it('SCH-038 shows why the move was refused, and the same day can be tapped again', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('workout_move', 422, refusal('That day already has a workout.'))).mockResolvedValueOnce({});
        const wrapper = sheet(TodayMoveDaySheet, { payload: day });

        await wrapper.findAll('[role="radio"]')[0]!.trigger('click');
        await flushPromises();
        expect(wrapper.find('[role="alert"]').text()).toBe('That day already has a workout.');

        await wrapper.findAll('[role="radio"]')[0]!.trigger('click');
        await flushPromises();
        expect(writeMock).toHaveBeenCalledTimes(2);
    });

    it('SCH-051 says when no other day this week is free', () => {
        const busy = { ...day, workouts: [{ ...workout, move_days: [] }] };
        const wrapper = sheet(TodayMoveDaySheet, { payload: busy });

        expect(wrapper.text()).toContain('No other day this week is free. A workout stays in its plan week.');
    });

    it('SCH-051 has nothing to move on a day without a workout (today_show.empty)', () => {
        const wrapper = sheet(TodayMoveDaySheet, { payload: today('empty') });

        expect(wrapper.text()).toContain('This day has no workout.');
    });

    it('OFF-008 says the day is waiting to sync (today_show.warning)', () => {
        const wrapper = sheet(TodayMoveDaySheet, { payload: today('warning'), pending: true });

        expect(wrapper.text()).toContain('Waiting to sync');
    });

    it('CAP-002 reads the day with a skeleton, then says why it failed', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('today_show', 500, null));
        const wrapper = sheet(TodayMoveDaySheet, { date: '2026-09-23' });
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        await flushPromises();

        expect(wrapper.text()).toContain('This day could not be loaded.');
    });
});
