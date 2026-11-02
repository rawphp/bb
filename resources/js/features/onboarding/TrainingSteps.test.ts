import { flushPromises, type VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { read, writeNow } from '@/app/call';
import { answers, commit, fixture, radio, stepper, view } from './onboardingTesting';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), writeNow: vi.fn() }));

const experience = fixture('experience');
const trainingDays = fixture('training_days');
const trainingPlan = fixture('training_plan');

describe('training steps', () => {
    let wrapper: VueWrapper | undefined;

    beforeEach(() => {
        wrapper?.unmount();
        vi.mocked(read).mockReset().mockResolvedValue(trainingDays);
        vi.mocked(writeNow).mockReset().mockResolvedValue({});
    });

    describe('step 7, training experience', () => {
        beforeEach(() => vi.mocked(read).mockResolvedValue(experience));

        it('ONB-010 shows the three levels with how long their mesocycles start, the saved one picked', () => {
            wrapper = view(experience);

            expect(wrapper.get('h2').text()).toBe('Training experience');
            for (const option of experience.experience.options) expect(wrapper.text()).toContain(option.detail);
            expect(radio(wrapper, 'Training experience', 'Intermediate').attributes('aria-checked')).toBe('true');
        });

        it('ONB-010 sends the level picked', async () => {
            wrapper = view(experience);

            await radio(wrapper, 'Training experience', 'Advanced').trigger('click');
            await flushPromises();

            expect(answers()).toEqual([{ experience: 'advanced' }]);
        });

        it('ONB-010 shows no level picked before one is saved', () => {
            const empty = structuredClone(experience);
            empty.experience.options.forEach((option) => (option.is_selected = false));
            wrapper = view(empty);

            expect(wrapper.findAll('[role="radio"][aria-checked="true"]')).toHaveLength(0);
        });
    });

    describe('step 8, training days', () => {
        it('ONB-011 shows the help, the weekdays and a start time and length for each training day', () => {
            wrapper = view(trainingDays);

            expect(wrapper.text()).toContain('2 to 4 days you can repeat every week beat 5 or 6 you will miss.');
            const on = wrapper.findAll('button[aria-pressed="true"]').map((day) => day.attributes('aria-label'));
            expect(on).toEqual(['Monday', 'Wednesday', 'Friday']);
            expect(wrapper.findAll('input[type="time"]')).toHaveLength(3);
            expect(wrapper.text()).toContain('Length: 60 min');
        });

        it('ONB-011 shows no day panels while no day is picked', () => {
            const empty = structuredClone(trainingDays);
            empty.training_days.days.forEach((day) => (day.is_on = false));
            wrapper = view(empty);

            expect(wrapper.findAll('input[type="time"]')).toHaveLength(0);
            expect(wrapper.findAll('button[aria-pressed]')).toHaveLength(7);
        });

        it('ONB-011 a day switched on is sent with the other training days, with no time or length so the server gives its default', async () => {
            wrapper = view(trainingDays);

            await wrapper.get('button[aria-label="Tuesday"]').trigger('click');
            await flushPromises();

            expect(answers()).toEqual([
                {
                    training_days: [
                        { weekday: 1, start_minutes: 1080, length_minutes: 60 },
                        { weekday: 2 },
                        { weekday: 3, start_minutes: 1080, length_minutes: 60 },
                        { weekday: 5, start_minutes: 1080, length_minutes: 60 },
                    ],
                },
            ]);
        });

        it('ONB-011 a day switched off is left out of the training days sent', async () => {
            wrapper = view(trainingDays);

            await wrapper.get('button[aria-label="Wednesday"]').trigger('click');
            await flushPromises();

            expect(answers()[0]!.training_days).toEqual([
                { weekday: 1, start_minutes: 1080, length_minutes: 60 },
                { weekday: 5, start_minutes: 1080, length_minutes: 60 },
            ]);
        });

        it('ONB-011 sends a start time picked and a length stepped for that day only', async () => {
            wrapper = view(trainingDays);

            await commit(wrapper.findAll('input[type="time"]')[1]!, '07:30');
            await flushPromises();
            await stepper(wrapper, 'Friday length', 'More').trigger('click');
            await flushPromises();

            const [time, length] = answers();
            expect(time!.training_days).toContainEqual({ weekday: 3, start_minutes: 450, length_minutes: 60 });
            expect(time!.training_days).toContainEqual({ weekday: 1, start_minutes: 1080, length_minutes: 60 });
            expect(length!.training_days).toContainEqual({ weekday: 5, start_minutes: 1080, length_minutes: 75 });
        });

        it('ONB-011 the length stepper stops at the server\'s bounds', () => {
            const shortest = structuredClone(trainingDays);
            shortest.training_days.days[0]!.length_minutes = 15;
            wrapper = view(shortest);

            expect(stepper(wrapper, 'Monday length', 'Fewer').attributes('disabled')).toBeDefined();
            expect(stepper(wrapper, 'Monday length', 'More').attributes('disabled')).toBeUndefined();
        });

        it('UI-007 Next is off with the server\'s reason while no day is picked', () => {
            const empty = structuredClone(trainingDays);
            empty.training_days.days.forEach((day) => (day.is_on = false));
            empty.step = { ...empty.step, can_continue: false, continue_hint: 'Pick at least one training day to continue.' };
            wrapper = view(empty);

            expect(wrapper.get('button[data-step="next"]').attributes('disabled')).toBeDefined();
            expect(wrapper.text()).toContain('Pick at least one training day to continue.');
        });
    });

    describe('step 9, training plan', () => {
        beforeEach(() => vi.mocked(read).mockResolvedValue(trainingPlan));

        it('ONB-012 shows the note, the fitting templates with the recommended one tagged and picked, then the Meso Builder and later', () => {
            wrapper = view(trainingPlan);

            expect(wrapper.text()).toContain('Templates that fit your 3 training days, sex and goal.');
            const rows = wrapper.get('[role="radiogroup"][aria-label="Training plan"]').findAll('[role="radio"]');
            expect(rows.map((row) => row.attributes('aria-label'))).toEqual([
                'Full Body 3-Day Dumbbells, recommended',
                'Upper / Lower 3-Day',
                'Meso Builder',
                "I'll build it later",
            ]);
            expect(rows[0]!.attributes('aria-checked')).toBe('true');
        });

        it('ONB-012 sends the plan picked', async () => {
            wrapper = view(trainingPlan);

            await radio(wrapper, 'Training plan', "I'll build it later").trigger('click');
            await flushPromises();
            await radio(wrapper, 'Training plan', 'Upper / Lower 3-Day').trigger('click');
            await flushPromises();

            expect(answers()).toEqual([{ training_plan: 'later' }, { training_plan: 'template:14' }]);
        });

        it('ONB-012 with no template that fits shows only the Meso Builder and later, and no note', () => {
            const none = structuredClone(trainingPlan);
            none.training_plan = { note: '', options: none.training_plan.options.filter((option) => !option.value.startsWith('template:')) };
            wrapper = view(none);

            expect(wrapper.findAll('[role="radio"]')).toHaveLength(2);
            expect(wrapper.text()).not.toContain('Templates that fit');
        });
    });
});
