import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import GalleryView from '@/features/dev/GalleryView.vue';
import ToggleSwitch from './ToggleSwitch.vue';

function toggle(props: Record<string, unknown> = {}, attrs: Record<string, unknown> = {}) {
    return mount(ToggleSwitch, { props: { label: 'Coach', modelValue: false, ...props }, attrs });
}

describe('ToggleSwitch', () => {
    it('AGT-020 is a labelled switch that shows the model on or off', () => {
        const off = toggle();
        const on = toggle({ modelValue: true });

        expect(off.get('label').text()).toBe('Coach');
        expect(off.get<HTMLInputElement>('input[type="checkbox"][role="switch"]').element.checked).toBe(false);
        expect(on.get<HTMLInputElement>('[role="switch"]').element.checked).toBe(true);
        expect(off.find('[aria-hidden="true"]').exists()).toBe(true);
    });

    it('AGT-020 sends each change of the switch as the new model value', async () => {
        const wrapper = toggle();

        await wrapper.get('[role="switch"]').setValue(true);
        await wrapper.get('[role="switch"]').setValue(false);

        expect(wrapper.emitted('update:modelValue')).toEqual([[true], [false]]);
    });

    it('AGT-020 can be disabled, and marks itself busy while a change is with the server', () => {
        const idle = toggle();
        const held = toggle({ disabled: true, busy: true });

        expect(idle.get('[role="switch"]').attributes('disabled')).toBeUndefined();
        expect(idle.get('[role="switch"]').attributes('aria-busy')).toBe('false');
        expect(held.get('[role="switch"]').attributes('disabled')).toBeDefined();
        expect(held.get('[role="switch"]').attributes('aria-busy')).toBe('true');
    });

    it('AGT-020 keeps the caller\'s classes on the row and its other attributes and listeners on the switch', async () => {
        const changes: Event[] = [];
        const wrapper = toggle({}, { class: 'rounded-2xl px-4', 'data-test': 'coach', onChange: (event: Event) => changes.push(event) });

        expect(wrapper.get('label').classes()).toEqual(expect.arrayContaining(['rounded-2xl', 'px-4']));
        expect(wrapper.get('[role="switch"]').attributes('data-test')).toBe('coach');
        await wrapper.get('[role="switch"]').setValue(true);

        expect(changes).toHaveLength(1);
        expect(wrapper.emitted('update:modelValue')).toEqual([[true]]);
    });

    it('VIEW-004 has a gallery entry', () => {
        const gallery = mount(GalleryView);

        expect(gallery.findAll('h2').map((h) => h.text())).toContain('ToggleSwitch');
    });
});
