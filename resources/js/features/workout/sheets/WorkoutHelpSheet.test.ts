import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { WorkoutShowOutput } from '@/contracts/workout_show';
import { screenFixture } from '@/testing/schemas';
import WorkoutHelpSheet from './WorkoutHelpSheet.vue';

const payload = () => screenFixture<WorkoutShowOutput>('workout_show');
const show = (data = payload()) => mount(WorkoutHelpSheet, { props: { payload: data }, global: { stubs: { teleport: true, RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } } } });

describe('WorkoutHelpSheet', () => {
    it('WO-030 shows every help section the server sent, in order, with its words', () => {
        const wrapper = show();

        expect(wrapper.get('[role="dialog"] h2').text()).toBe('Workout help');
        const sections = wrapper.findAll('[data-test="section"]');
        expect(sections.map((section) => section.get('h3').text())).toEqual(payload().help.map((section) => section.title));
        expect(sections[0]!.text()).toContain('There is no rest timer.');
    });

    it('WO-052 covers training while sick, alongside a sport and long workouts', () => {
        const titles = show().findAll('h3').map((heading) => heading.text());

        expect(titles).toEqual(expect.arrayContaining(['Training while sick', 'Training alongside a sport', 'Long workouts']));
    });

    it('UI-009 a section with an article links to it with Read more; one without has none', () => {
        const wrapper = show();

        const sections = wrapper.findAll('[data-test="section"]');
        expect(sections[0]!.get('a').attributes('href')).toBe('/learn/rest-times');
        expect(sections[2]!.find('a').exists()).toBe(false);
    });

    it('WO-030 Done closes', async () => {
        const wrapper = show();

        await wrapper.get('[data-cancel]').trigger('click');

        expect(wrapper.emitted('close')).toHaveLength(1);
    });
});
