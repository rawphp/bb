import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import RemainingWeeksCheckbox from './RemainingWeeksCheckbox.vue';

describe('RemainingWeeksCheckbox', () => {
    it('MESO-045 is off by default: this session only', () => {
        const wrapper = mount(RemainingWeeksCheckbox, { props: { modelValue: false } });

        const box = wrapper.get('[role="checkbox"]');
        expect(box.attributes('aria-checked')).toBe('false');
        expect(box.text()).toContain('Apply to all remaining weeks');
        expect(box.text()).toContain('Off: this session only.');
    });

    it('MESO-045 on, it says the day changes in every week not yet done, and a tap flips it', async () => {
        const wrapper = mount(RemainingWeeksCheckbox, { props: { modelValue: true } });

        expect(wrapper.get('[role="checkbox"]').attributes('aria-checked')).toBe('true');
        expect(wrapper.text()).toContain('This day changes in every week not yet done.');
        await wrapper.get('[role="checkbox"]').trigger('click');

        expect(wrapper.emitted('update:modelValue')).toEqual([[false]]);
    });
});
