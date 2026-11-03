import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import TodayTourOverlay from './TodayTourOverlay.vue';

const TITLES = ['Days and calories', "The day's totals", 'Meals', 'Workouts', 'Weigh-ins', 'The menu', 'The tabs'];

describe('TodayTourOverlay', () => {
    it('ONB-030 walks the 7 steps in order: strip, totals, meal, workout, weigh-in, menu, tabs', async () => {
        const wrapper = mount(TodayTourOverlay, { attachTo: document.body });
        const seen: string[] = [];

        for (let step = 1; step <= 7; step++) {
            seen.push(wrapper.get('h2').text());
            expect(wrapper.text()).toContain(`${step} of 7`);
            expect(wrapper.get('[data-test="next"]').text()).toBe(step === 7 ? 'Done' : 'Next');
            await wrapper.get('[data-test="next"]').trigger('click');
        }

        expect(seen).toEqual(TITLES);
        expect(wrapper.emitted('done')).toHaveLength(1);
        wrapper.unmount();
    });

    it('ONB-030 can be skipped at any step', async () => {
        const wrapper = mount(TodayTourOverlay, { attachTo: document.body });

        await wrapper.get('[data-test="next"]').trigger('click');
        await wrapper.get('[data-test="skip"]').trigger('click');

        expect(wrapper.emitted('done')).toHaveLength(1);
        wrapper.unmount();
    });

    it('UI-030 is a modal dialog named by its step', () => {
        const wrapper = mount(TodayTourOverlay, { attachTo: document.body });

        const dialog = wrapper.get('[role="dialog"]');
        expect(dialog.attributes('aria-modal')).toBe('true');
        expect(document.getElementById(dialog.attributes('aria-labelledby')!)?.textContent).toBe('Days and calories');
        wrapper.unmount();
    });
});
