import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import Stepper from './Stepper.vue';

function stepper(props: Partial<{ modelValue: number; min: number; max: number; step: number; disabled: boolean }> = {}) {
    return mount(Stepper, { props: { label: 'Length', text: 'Length: 60 min', modelValue: 60, min: 15, max: 300, step: 15, ...props } });
}

describe('Stepper', () => {
    it('ONB-011 shows the server\'s text and steps by its increment', async () => {
        const wrapper = stepper();

        expect(wrapper.text()).toContain('Length: 60 min');
        await wrapper.get('button[aria-label="More: Length"]').trigger('click');
        await wrapper.get('button[aria-label="Fewer: Length"]').trigger('click');

        expect(wrapper.emitted('update:modelValue')).toEqual([[75], [45]]);
    });

    it('ONB-013 does not step past the bounds, and turns that side off', async () => {
        const top = stepper({ modelValue: 300 });
        expect(top.get('button[aria-label="More: Length"]').attributes('disabled')).toBeDefined();

        const near = stepper({ modelValue: 0, min: 0, max: 40000, step: 500 });
        expect(near.get('button[aria-label="Fewer: Length"]').attributes('disabled')).toBeDefined();
        await near.get('button[aria-label="More: Length"]').trigger('click');
        expect(near.emitted('update:modelValue')).toEqual([[500]]);
    });

    it('CAP-010 sends nothing while disabled', () => {
        const wrapper = stepper({ disabled: true });

        for (const button of wrapper.findAll('button')) expect(button.attributes('disabled')).toBeDefined();
    });
});
