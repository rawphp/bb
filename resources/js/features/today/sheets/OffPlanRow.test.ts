import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { TodayShowOutput } from '@/contracts/today_show';
import { screenFixture } from '@/testing/schemas';
import OffPlanRow from './OffPlanRow.vue';

const entry = screenFixture<TodayShowOutput>('today_show').off_plan[0]!;

describe('OffPlanRow', () => {
    it('DAY-001 shows an off-plan entry with its time and macros, and no check-in box', () => {
        const wrapper = mount(OffPlanRow, { props: { entry } });

        expect(wrapper.text()).toContain('Office cake');
        expect(wrapper.text()).toContain('3:00 PM');
        for (const text of ['320 kcal', 'P 4', 'F 16', 'C 40']) expect(wrapper.text()).toContain(text);
        expect(wrapper.find('input, button').exists()).toBe(false);
    });

    it('UI-011 colours each macro and reads the line aloud in words', () => {
        const wrapper = mount(OffPlanRow, { props: { entry } });

        const line = wrapper.get('[data-test="macros"]');
        expect(line.attributes('aria-hidden')).toBe('true');
        expect(wrapper.get('.sr-only').text()).toBe(entry.macros.label);
        expect(line.findAll('span').map((span) => span.classes()[0])).toEqual(['text-macro-calories', 'text-macro-protein', 'text-macro-fat', 'text-macro-carbs']);
    });

    it('OFF-009 shows the entry as typed with a pending mark while it waits to sync', () => {
        const wrapper = mount(OffPlanRow, { props: { entry, pending: true } });

        expect(wrapper.find('[data-mark]').exists()).toBe(true);
        expect(wrapper.text()).toContain('320 kcal');
    });

    it('OFF-009 shows no mark when nothing waits to sync', () => {
        const wrapper = mount(OffPlanRow, { props: { entry } });

        expect(wrapper.find('[data-mark]').exists()).toBe(false);
    });
});
