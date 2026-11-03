import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { TodayShowOutput } from '@/contracts/today_show';
import { screenFixture } from '@/testing/schemas';
import TodayBusyCard from './TodayBusyCard.vue';
import TodayMealCard from './TodayMealCard.vue';
import TodayOtherActivityCard from './TodayOtherActivityCard.vue';
import TodayStepRow from './TodayStepRow.vue';
import TodayTotalsBar from './TodayTotalsBar.vue';
import TodayWeighInRow from './TodayWeighInRow.vue';
import TodayWorkoutCard from './TodayWorkoutCard.vue';

const today = (state?: string) => screenFixture<TodayShowOutput>(state ? `today_show.${state}` : 'today_show');
const breakfast = () => today().meals[0]!;
const preWorkout = () => today().meals[2]!;

describe('TodayMealCard', () => {
    it('SCH-002 shows the meal name, time, target calories and macros, and the foods planned', () => {
        const wrapper = mount(TodayMealCard, { props: { meal: breakfast(), condensed: false } });

        expect(wrapper.find('h3').text()).toBe('Breakfast');
        expect(wrapper.text()).toContain('8:00 AM');
        expect(wrapper.find('[data-test="macros"]').text()).toBe('780 kcal P 51 F 21 C 96');
        expect(wrapper.text()).toContain('780 kcal, 51 grams protein, 21 grams fat, 96 grams carbs');
        expect(wrapper.text()).toContain('Oats 80 g · Whey protein 30 g');
        expect(wrapper.text()).toContain('Eaten: 780 kcal, 51 g protein');
    });

    it('SCH-008 shows the plan banner on a meal with no foods', () => {
        const wrapper = mount(TodayMealCard, { props: { meal: preWorkout(), condensed: false } });

        expect(wrapper.find('[data-test="plan-banner"]').text()).toBe('Plan this meal before you eat it');
    });

    it('SCH-007 collapses to one line in the condensed view, keeping the Adjust foods badge (DAY-003)', () => {
        const lunch = today('warning').meals[1]!;
        const wrapper = mount(TodayMealCard, { props: { meal: lunch, condensed: true } });

        expect(wrapper.text()).toContain('12:30 PM · 620 kcal');
        expect(wrapper.find('[data-test="macros"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Chicken breast');
        expect(wrapper.text()).toContain('Adjust foods');
    });

    it('MEAL-047 shows the check-in state the server sends on its box', () => {
        const wrapper = mount(TodayMealCard, { props: { meal: breakfast(), condensed: false } });

        const box = wrapper.get('[role="checkbox"]');
        expect(box.attributes('aria-checked')).toBe('true');
        expect(box.attributes('aria-label')).toBe('Breakfast eaten');
        expect(box.attributes('aria-description')).toBe('Checked in, on track');
    });

    it('SCH-009 checks the meal with one tap on its box, without opening the meal', async () => {
        const wrapper = mount(TodayMealCard, { props: { meal: breakfast(), condensed: false } });

        await wrapper.get('[role="checkbox"]').trigger('click');

        expect(wrapper.emitted('toggle')).toHaveLength(1);
        expect(wrapper.emitted('open')).toBeUndefined();
    });

    it('NAV-002 opens the meal when the card is tapped', async () => {
        const wrapper = mount(TodayMealCard, { props: { meal: breakfast(), condensed: false } });

        await wrapper.get('[data-test="meal-card"]').trigger('click');

        expect(wrapper.emitted('open')).toHaveLength(1);
    });

    it('SCH-010 a long press opens the meal menu', async () => {
        const wrapper = mount(TodayMealCard, { props: { meal: breakfast(), condensed: false } });

        await wrapper.get('[data-test="meal-card"]').trigger('contextmenu');

        expect(wrapper.emitted('menu')).toHaveLength(1);
    });

    it('SCH-010 swiping left shows Edit macros and Delete', async () => {
        const wrapper = mount(TodayMealCard, { props: { meal: breakfast(), condensed: false } });

        await wrapper.get('[data-test="swipe-edit"]').trigger('click');
        await wrapper.get('[data-test="swipe-delete"]').trigger('click');

        expect(wrapper.get('[data-test="swipe-edit"]').text()).toBe('Edit macros');
        expect(wrapper.get('[data-test="swipe-delete"]').text()).toBe('Delete');
        expect(wrapper.emitted('edit')).toHaveLength(1);
        expect(wrapper.emitted('delete')).toHaveLength(1);
    });

    it('OFF-008 dims the server-derived numbers while a write waits to sync', () => {
        const wrapper = mount(TodayMealCard, { props: { meal: breakfast(), condensed: false, pending: true } });

        expect(wrapper.text()).toContain('Waiting to sync');
    });

    it('OFF-009 shows a pending check-in as ticked, as the user made it', () => {
        const lunch = today().meals[1]!;
        const wrapper = mount(TodayMealCard, { props: { meal: lunch, condensed: false, pendingCheck: true } });

        expect(wrapper.get('[role="checkbox"]').attributes('aria-checked')).toBe('true');
        expect(wrapper.text()).toContain('Waiting to sync');
    });
});

describe('TodayWorkoutCard', () => {
    const workout = () => today().workouts[0]!;

    it('SCH-003 shows the session name, start time, length, exercises, sets and Start', async () => {
        const wrapper = mount(TodayWorkoutCard, { props: { workout: workout(), condensed: false } });

        expect(wrapper.find('h3').text()).toBe('Week 2 · Day 2 · Upper A');
        expect(wrapper.text()).toContain('5:30 PM');
        expect(wrapper.text()).toContain('55 min · 5 exercises · 18 sets');
        expect(wrapper.text()).toContain('Bench press');
        expect(wrapper.text()).toContain('4 sets');

        await wrapper.get('button').trigger('click');
        expect(wrapper.emitted('start')).toHaveLength(1);
    });

    it('SCH-003 shows Done and the sets completed after the workout', () => {
        const wrapper = mount(TodayWorkoutCard, { props: { workout: today('warning').workouts[0]!, condensed: false } });

        expect(wrapper.text()).toContain('Done');
        expect(wrapper.text()).toContain('16 of 18 sets completed');
        expect(wrapper.find('button').exists()).toBe(false);
    });

    it('SCH-007 collapses to the name and one line in the condensed view', () => {
        const wrapper = mount(TodayWorkoutCard, { props: { workout: workout(), condensed: true } });

        expect(wrapper.find('h3').text()).toBe('Upper A');
        expect(wrapper.text()).toContain('5:30 PM · 55 min');
        expect(wrapper.text()).not.toContain('Bench press');
    });

    it('SCH-050 a long press opens the workout menu', async () => {
        const wrapper = mount(TodayWorkoutCard, { props: { workout: workout(), condensed: false } });

        await wrapper.get('[data-test="workout-card"]').trigger('contextmenu');

        expect(wrapper.emitted('menu')).toHaveLength(1);
    });
});

describe('TodayOtherActivityCard', () => {
    it('SCH-032 shows the activity at its start time, its length and intensity', async () => {
        const wrapper = mount(TodayOtherActivityCard, { props: { activity: today().other_activities[0]! } });

        expect(wrapper.find('h3').text()).toBe('Tennis');
        expect(wrapper.text()).toContain('6:45 PM · 45 min');
        expect(wrapper.text()).toContain('Moderate');

        await wrapper.get('[data-test="activity-card"]').trigger('contextmenu');
        expect(wrapper.emitted('menu')).toHaveLength(1);
    });
});

describe('TodayWeighInRow', () => {
    it('SCH-004 shows the scheduled time and the logged weight', () => {
        const wrapper = mount(TodayWeighInRow, { props: { weighIn: today().weigh_ins[0]! } });

        expect(wrapper.text()).toContain('Weigh-in');
        expect(wrapper.text()).toContain('7:00 AM');
        expect(wrapper.text()).toContain('82.4 kg');
        expect(wrapper.find('button').exists()).toBe(false);
    });

    it('SCH-004 offers Add weigh-in until one is logged', async () => {
        const wrapper = mount(TodayWeighInRow, { props: { weighIn: { id: 'weigh-in', time_text: '7:00 AM', weight_text: null } } });

        await wrapper.get('button').trigger('click');

        expect(wrapper.get('button').text()).toBe('Add weigh-in');
        expect(wrapper.emitted('add')).toHaveLength(1);
    });
});

describe('TodayBusyCard', () => {
    it('SCH-005 shows a busy period greyed, with its label and times', () => {
        const wrapper = mount(TodayBusyCard, { props: { busy: today().busy_periods[0]! } });

        expect(wrapper.text()).toContain('Team meeting');
        expect(wrapper.text()).toContain('9:00 AM – 10:30 AM');
        expect(wrapper.get('[data-test="busy-card"]').classes()).toContain('opacity-60');
        expect(wrapper.get('[data-test="busy-card"]').attributes('aria-label')).toBe('Busy: Team meeting, 9:00 AM – 10:30 AM');
        expect(wrapper.find('button').exists()).toBe(false);
    });
});

describe('TodayTotalsBar', () => {
    const totals = () => today().totals!;

    it('SCH-006 shows calories and protein against target and the day type', () => {
        const wrapper = mount(TodayTotalsBar, { props: { totals: totals() } });

        expect(wrapper.text()).toContain('Day totals');
        expect(wrapper.text()).toContain('Hard');
        expect(wrapper.text()).toContain('1820 under');
        expect(wrapper.text()).toContain('780/2600');
        expect(wrapper.text()).toContain('51/170');
    });

    it('SCH-006 Adjust, Redistribute (DAY-002) and Save food macros (DAY-001) each say so', async () => {
        const wrapper = mount(TodayTotalsBar, { props: { totals: totals() } });

        await wrapper.get('[aria-label="Redistribute"]').trigger('click');
        await wrapper.get('[aria-label="Save food macros"]').trigger('click');
        await wrapper.get('[data-test="adjust"]').trigger('click');

        expect(wrapper.emitted('redistribute')).toHaveLength(1);
        expect(wrapper.emitted('saveFood')).toHaveLength(1);
        expect(wrapper.emitted('adjust')).toHaveLength(1);
    });

    it('DAY-002 turns the wand off when the server says it is off', () => {
        const wrapper = mount(TodayTotalsBar, { props: { totals: today('empty').totals! } });

        expect(wrapper.get('[aria-label="Redistribute"]').attributes('disabled')).toBeDefined();
    });

    it('SAFE-018 lists the day\'s nutrient warnings', () => {
        const wrapper = mount(TodayTotalsBar, { props: { totals: today('warning').totals! } });

        expect(wrapper.text()).toContain('Fat is below 20% of calories today.');
    });

    it('OFF-008 marks the totals as waiting to sync', () => {
        const wrapper = mount(TodayTotalsBar, { props: { totals: totals(), pending: true } });

        expect(wrapper.text()).toContain('Waiting to sync');
    });
});

describe('TodayStepRow', () => {
    it('SCH-011 shows the day\'s step target with the actual steps', () => {
        const wrapper = mount(TodayStepRow, { props: { steps: today().steps! } });

        expect(wrapper.text()).toBe('Step count target 6-8k · 5,432 steps');
    });

    it('SCH-011 shows the target alone before any steps come in', () => {
        const wrapper = mount(TodayStepRow, { props: { steps: { target_text: 'Step count target 0-8k', actual_text: null } } });

        expect(wrapper.text()).toBe('Step count target 0-8k');
    });
});
