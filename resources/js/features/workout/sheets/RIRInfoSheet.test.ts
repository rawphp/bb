import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { WorkoutShowOutput } from '@/contracts/workout_show';
import { screenFixture } from '@/testing/schemas';
import RIRInfoSheet from './RIRInfoSheet.vue';

const payload = () => screenFixture<WorkoutShowOutput>('workout_show');
const show = (data = payload()) => mount(RIRInfoSheet, { props: { payload: data }, global: { stubs: { teleport: true, RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } } } });

describe('RIRInfoSheet', () => {
    it("WO-007 explains the target and this week's RIR, with what RIR means", () => {
        const wrapper = show();

        expect(wrapper.get('[role="dialog"] h2').text()).toBe('Reps and RIR');
        expect(wrapper.text()).toContain(payload().rir_info.title);
        expect(wrapper.text()).toContain("This week's target is 2 RIR: stop each set with 2 reps left in the tank.");
        expect(wrapper.text()).toContain('RIR is reps in reserve: how many more reps you could have done.');
    });

    it('WO-007 Read more goes to the article', () => {
        const wrapper = show();

        expect(wrapper.get('a').attributes('href')).toBe('/learn/rir');
        expect(wrapper.get('a').text()).toBe('Read more');
    });

    it('WO-007 has no Read more when the info names no article', () => {
        const data = payload();
        data.rir_info.article = null;

        expect(show(data).find('a').exists()).toBe(false);
    });

    it('WO-007 Done closes', async () => {
        const wrapper = show();

        await wrapper.get('[data-cancel]').trigger('click');

        expect(wrapper.emitted('close')).toHaveLength(1);
    });
});
