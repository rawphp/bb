import { flushPromises, type VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { read, write } from '@/app/call';
import { answers, commit, fixture, radio, stepper, view } from './onboardingTesting';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), write: vi.fn() }));

const steps = fixture('steps');
const meals = fixture('meals');
const weighIns = fixture('weigh_ins');
const calories = fixture('calories');

/** The seven days' ranges as the fixture has them, weekdays 5k to 8k and weekends 8k to 10k. */
const savedRanges = steps.steps.days.map((day) => ({ weekday: day.weekday, min: day.min, max: day.max }));

const commute = { id: '01JBX0Q5ZK3J7Y8T6V2N4M1C9D', label: 'Commute', start_minutes: 480, end_minutes: 540, weekdays: [1, 2, 3, 4, 5] };

describe('lifestyle steps', () => {
    let wrapper: VueWrapper | undefined;

    beforeEach(() => {
        wrapper?.unmount();
        vi.mocked(read).mockReset().mockResolvedValue(steps);
        vi.mocked(write).mockReset().mockResolvedValue({});
    });

    describe('step 10, daily steps', () => {
        it('ONB-013 shows the note and a weekdays and a weekends range with presets', () => {
            wrapper = view(steps);

            expect(wrapper.get('h2').text()).toBe('Daily steps');
            expect(wrapper.text()).toContain('Your typical daily step count. A range for each day is fine.');
            expect(wrapper.text()).toContain('5,000 to 8,000 steps');
            expect(wrapper.text()).toContain('8,000 to 10,000 steps');
            const presets = wrapper.get('select[aria-label="Weekdays presets"]').findAll('option:not([disabled])');
            expect(presets.map((option) => option.text())).toEqual(steps.steps.presets.map((preset) => preset.title));
        });

        it('ONB-013 a preset sets every day of its group, and the other days are sent as saved', async () => {
            wrapper = view(steps);

            await wrapper.get('select[aria-label="Weekends presets"]').setValue('5');
            await flushPromises();

            expect(answers()).toEqual([
                { step_ranges: savedRanges.map((range) => (range.weekday >= 6 ? { ...range, min: 8000, max: 13000 } : range)) },
            ]);
            expect((wrapper.get('select[aria-label="Weekends presets"]').element as HTMLSelectElement).value).toBe('');
        });

        it('ONB-013 the steppers move a group by the server\'s increment', async () => {
            wrapper = view(steps);

            await stepper(wrapper, 'Weekdays most', 'More').trigger('click');
            await flushPromises();
            await stepper(wrapper, 'Weekdays fewest', 'Fewer').trigger('click');
            await flushPromises();

            const [most, fewest] = answers();
            expect(most!.step_ranges).toEqual(savedRanges.map((range) => (range.weekday <= 5 ? { ...range, max: 8500 } : range)));
            expect(fewest!.step_ranges).toEqual(savedRanges.map((range) => (range.weekday <= 5 ? { ...range, min: 4500 } : range)));
        });

        it('ONB-013 sets one day from Set each day', async () => {
            wrapper = view(steps);
            expect(wrapper.text()).toContain('Set each day');

            await stepper(wrapper, 'Sunday most', 'More').trigger('click');
            await flushPromises();

            expect(answers()[0]!.step_ranges).toEqual(savedRanges.map((range) => (range.weekday === 7 ? { ...range, max: 10500 } : range)));
        });

        it('ONB-013 a group whose days differ says so in place of its steppers', () => {
            const mixed = structuredClone(steps);
            mixed.steps.groups[0]!.is_mixed = true;
            wrapper = view(mixed);

            expect(wrapper.text()).toContain('These days have different ranges. Pick a preset to make them the same.');
            expect(wrapper.find('button[aria-label="More: Weekdays most"]').exists()).toBe(false);
        });

        it('SAFE-015 shows the server\'s step warning', () => {
            const warned = structuredClone(steps);
            warned.notices = [{ text: 'Over 20,000 steps a day is a lot to keep up.', tone: 'warning' }];
            wrapper = view(warned);

            expect(wrapper.text()).toContain('Over 20,000 steps a day is a lot to keep up.');
        });
    });

    describe('step 11, meals', () => {
        beforeEach(() => vi.mocked(read).mockResolvedValue(meals));

        it('ONB-014 shows the eating window, the meal count and the busy periods', () => {
            wrapper = view(meals);

            const times = wrapper.findAll('input[type="time"]').map((input) => (input.element as HTMLInputElement).value);
            expect(times).toEqual(['07:00', '20:00', '08:00', '09:00']);
            expect(wrapper.text()).toContain('This window applies to every day.');
            expect(wrapper.text()).toContain('Meals a day: 5');
            expect((wrapper.get('input[aria-label="Label"]').element as HTMLInputElement).value).toBe('Commute');
            const on = wrapper.findAll('button[aria-pressed="true"]').map((day) => day.attributes('aria-label'));
            expect(on).toEqual(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']);
        });

        it('ONB-014 with no busy periods shows only the add button', () => {
            const empty = structuredClone(meals);
            empty.meals.busy_periods = [];
            wrapper = view(empty);

            expect(wrapper.find('input[aria-label="Label"]').exists()).toBe(false);
            expect(wrapper.get('[data-test="add-busy-period"]').text()).toContain('Add a busy period');
        });

        it('ONB-014 sends the first and last meal and the meal count', async () => {
            wrapper = view(meals);
            const [first, last] = wrapper.findAll('input[type="time"]');

            await commit(first!, '06:30');
            await flushPromises();
            await commit(last!, '21:00');
            await flushPromises();
            await stepper(wrapper, 'Meals a day', 'Fewer').trigger('click');
            await flushPromises();

            expect(answers()).toEqual([{ first_meal_minutes: 390 }, { last_meal_minutes: 1260 }, { meal_count: 4 }]);
        });

        it('ONB-014 the meal count stops at 6', () => {
            const most = structuredClone(meals);
            most.meals.meal_count = { ...most.meals.meal_count, value: 6, text: 'Meals a day: 6' };
            wrapper = view(most);

            expect(stepper(wrapper, 'Meals a day', 'More').attributes('disabled')).toBeDefined();
        });

        it('ONB-014 adds a busy period after the saved ones, as rp-clone starts one', async () => {
            wrapper = view(meals);

            await wrapper.get('[data-test="add-busy-period"]').trigger('click');
            await flushPromises();

            expect(answers()).toEqual([{ busy_periods: [commute, { label: 'Busy', start_minutes: 720, end_minutes: 780, weekdays: [1, 2, 3, 4, 5] }] }]);
        });

        it('ONB-014 changes a busy period\'s times, days and label, and removes it', async () => {
            wrapper = view(meals);
            const [, , from, to] = wrapper.findAll('input[type="time"]');
            const label = wrapper.get('input[aria-label="Label"]');

            await commit(from!, '08:15');
            await flushPromises();
            await commit(to!, '09:30');
            await flushPromises();
            await wrapper.get('button[aria-label="Saturday"]').trigger('click');
            await flushPromises();
            await wrapper.get('button[aria-label="Monday"]').trigger('click');
            await flushPromises();
            await label.trigger('focus');
            await commit(label, 'School run');
            await label.trigger('blur');
            await flushPromises();
            await wrapper.get('button[aria-label="Remove Commute"]').trigger('click');
            await flushPromises();

            expect(answers()).toEqual([
                { busy_periods: [{ ...commute, start_minutes: 495 }] },
                { busy_periods: [{ ...commute, end_minutes: 570 }] },
                { busy_periods: [{ ...commute, weekdays: [1, 2, 3, 4, 5, 6] }] },
                { busy_periods: [{ ...commute, weekdays: [2, 3, 4, 5] }] },
                { busy_periods: [{ ...commute, label: 'School run' }] },
                { busy_periods: [] },
            ]);
        });

        it('ONB-014 a busy period keeps its last weekday', async () => {
            const one = structuredClone(meals);
            one.meals.busy_periods[0]!.weekdays = [3];
            wrapper = view(one);

            await wrapper.get('button[aria-label="Wednesday"]').trigger('click');
            await flushPromises();

            expect(write).not.toHaveBeenCalled();
        });

        it('ONB-014 a label being typed is not wiped by a refresh', async () => {
            wrapper = view(meals);
            const label = wrapper.get('input[aria-label="Label"]');

            await label.trigger('focus');
            await label.setValue('Scho');
            const refreshed = structuredClone(meals);
            refreshed.meals.meal_count = { ...refreshed.meals.meal_count, value: 4, text: 'Meals a day: 4' };
            await wrapper.setProps({ payload: refreshed });

            expect((label.element as HTMLInputElement).value).toBe('Scho');
        });
    });

    describe('step 12, weigh-ins', () => {
        beforeEach(() => vi.mocked(read).mockResolvedValue(weighIns));

        it('ONB-015 shows the weigh-in days with a time each, the hint and that the time only sets the reminder', () => {
            wrapper = view(weighIns);

            expect(wrapper.findAll('input[type="time"]')).toHaveLength(3);
            expect(wrapper.text()).toContain('We recommend weighing in on 4 or more days a week for a clearer trend.');
            expect(wrapper.text()).toContain('The time only sets your reminder.');
        });

        it('ONB-015 a day switched on or off is sent with the other weigh-in days', async () => {
            wrapper = view(weighIns);
            const days = wrapper.findAll('button[aria-pressed]');

            await days[1]!.trigger('click');
            await flushPromises();
            await days[4]!.trigger('click');
            await flushPromises();

            expect(answers()).toEqual([
                { weigh_ins: [{ weekday: 1, minutes: 420 }, { weekday: 2 }, { weekday: 3, minutes: 420 }, { weekday: 5, minutes: 420 }] },
                { weigh_ins: [{ weekday: 1, minutes: 420 }, { weekday: 3, minutes: 420 }] },
            ]);
        });

        it('ONB-015 sends a time picked for that day', async () => {
            wrapper = view(weighIns);

            await commit(wrapper.findAll('input[type="time"]')[2]!, '06:45');
            await flushPromises();

            expect(answers()).toEqual([{ weigh_ins: [{ weekday: 1, minutes: 420 }, { weekday: 3, minutes: 420 }, { weekday: 5, minutes: 405 }] }]);
        });

        it('ONB-015 with too few days Next is off with the server\'s reason, and no hint shows once there are enough', () => {
            const few = structuredClone(weighIns);
            few.weigh_ins.days.forEach((day) => (day.is_on = day.weekday === 1));
            few.step = { ...few.step, can_continue: false, continue_hint: 'Too few weigh-ins to continue' };
            wrapper = view(few);
            expect(wrapper.get('button[data-step="next"]').attributes('disabled')).toBeDefined();
            expect(wrapper.text()).toContain('Too few weigh-ins to continue');

            wrapper.unmount();
            const enough = structuredClone(weighIns);
            enough.weigh_ins.hint = '';
            wrapper = view(enough);
            expect(wrapper.text()).not.toContain('We recommend');
        });

        it('ONB-016 asks for the review day, the server\'s pick selected, and sends the day picked', async () => {
            wrapper = view(weighIns);

            expect(wrapper.text()).toContain('It is also the first day of every plan week.');
            expect(radio(wrapper, 'Weekly review day', 'Sat').attributes('aria-checked')).toBe('true');
            await radio(wrapper, 'Weekly review day', 'Sat').trigger('click');
            await radio(wrapper, 'Weekly review day', 'Sun').trigger('click');
            await flushPromises();

            expect(answers()).toEqual([{ review_weekday: '7' }]);
        });
    });

    describe('step 13, calories', () => {
        beforeEach(() => vi.mocked(read).mockResolvedValue(calories));

        it('ONB-017 shows the recommendation picked with its daily average, and the week it gives', () => {
            wrapper = view(calories);

            expect(radio(wrapper, 'Calories', 'RP recommendation').attributes('aria-checked')).toBe('true');
            expect(radio(wrapper, 'Calories', 'RP recommendation').attributes('aria-label')).toBe('RP recommendation, recommended');
            expect(wrapper.text()).toContain('1,546 kcal a day');
            expect(wrapper.text()).toContain('Your week');
            for (const day of calories.calories.week) expect(wrapper.text()).toContain(day.kcal_text);
            expect(wrapper.find('input[aria-label="Daily average in kcal a day"]').exists()).toBe(false);
        });

        it('ONB-017 sends the choice, and the own daily average in whole kcal when the field is left', async () => {
            const own = structuredClone(calories);
            own.calories.options.forEach((option) => (option.is_selected = option.value === 'own'));
            own.calories.week = [];
            wrapper = view(calories);

            await radio(wrapper, 'Calories', 'Choose my own average daily calories').trigger('click');
            await flushPromises();
            wrapper.unmount();
            vi.mocked(read).mockResolvedValue(own);
            wrapper = view(own);
            const field = wrapper.get('input[aria-label="Daily average in kcal a day"]');
            await field.trigger('focus');
            await field.setValue('1800.4');
            await field.trigger('blur');
            await flushPromises();

            expect(answers()).toEqual([{ calories: 'own' }, { own_kcal: 1800 }]);
            expect(wrapper.text()).not.toContain('Your week');
        });

        it('OFF-008 marks the week the server worked out while a change waits to sync', () => {
            wrapper = view(calories, { pending: true });

            expect(wrapper.text()).toContain('Waiting to sync');
            const kcal = wrapper.findAll('span').filter((span) => span.text() === calories.calories.week[0]!.kcal_text);
            expect(kcal[0]!.classes()).toContain('opacity-60');
        });
    });
});
