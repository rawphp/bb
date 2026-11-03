import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { TodayShowOutput } from '@/contracts/today_show';
import { screenFixture } from '@/testing/schemas';
import NextMesocycleCard from './NextMesocycleCard.vue';

const next = () => screenFixture<TodayShowOutput>('today_show').next_mesocycle!;

describe('NextMesocycleCard', () => {
    it('MESO-060 shows the prompt, when the next mesocycle starts and each way to build it', () => {
        const wrapper = mount(NextMesocycleCard, { props: { next: next() } });

        expect(wrapper.find('h2').text()).toBe('Build your next mesocycle');
        expect(wrapper.text()).toContain('Starts Saturday, Oct 3');
        expect(wrapper.findAll('[data-test="option"]').map((b) => b.text())).toEqual(['Copy this mesocycle', 'Start from a template', 'Build from scratch']);
    });

    it('MESO-060 says which way the user chose', async () => {
        const wrapper = mount(NextMesocycleCard, { props: { next: next() } });

        await wrapper.findAll('[data-test="option"]')[1]!.trigger('click');

        expect(wrapper.emitted('choose')).toEqual([['template']]);
    });

    it('MESO-064 offers an active rest when one is recommended, with its later start', async () => {
        const wrapper = mount(NextMesocycleCard, { props: { next: next() } });

        expect(wrapper.text()).toContain('A week of active rest is recommended before your next mesocycle.');
        expect(wrapper.text()).toContain('Starts Saturday, Oct 10');
        await wrapper.get('[data-test="active-rest"]').trigger('click');

        expect(wrapper.emitted('takeActiveRest')).toHaveLength(1);
    });

    it('MESO-064 shows no active rest when none is recommended', () => {
        const wrapper = mount(NextMesocycleCard, { props: { next: { ...next(), active_rest: null } } });

        expect(wrapper.find('[data-test="active-rest"]').exists()).toBe(false);
    });

    it('CAP-014 keeps its buttons off while a choice is being sent', () => {
        const wrapper = mount(NextMesocycleCard, { props: { next: next(), busy: true } });

        expect(wrapper.get('[data-test="active-rest"]').attributes('disabled')).toBeDefined();
    });
});
