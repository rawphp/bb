import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import GalleryView from '@/features/dev/GalleryView.vue';
import SegmentedPicker from '@/design/SegmentedPicker.vue';

const options = [
    { value: 'cm', label: 'cm' },
    { value: 'feetInches', label: 'ft/in' },
    { value: 'm', label: 'm', disabled: true },
];

function picker(props: Record<string, unknown> = {}) {
    return mount(SegmentedPicker, { props: { label: 'Height unit', options, modelValue: 'cm', ...props } });
}

const segment = (wrapper: ReturnType<typeof picker>, label: string) => wrapper.findAll('[role="radio"]').find((radio) => radio.text() === label)!;

describe('SegmentedPicker', () => {
    it('ONB-004 is a labelled radio group with the chosen option checked', () => {
        const wrapper = picker();

        expect(wrapper.get('[role="radiogroup"]').attributes('aria-label')).toBe('Height unit');
        expect(wrapper.findAll('[role="radio"]').map((radio) => radio.attributes('aria-checked'))).toEqual(['true', 'false', 'false']);
    });

    it('ONB-004 sends the option picked', async () => {
        const wrapper = picker();

        await segment(wrapper, 'ft/in').trigger('click');

        expect(wrapper.emitted('update:modelValue')).toEqual([['feetInches']]);
    });

    it('ONB-004 sends nothing when the chosen option is picked again', async () => {
        const wrapper = picker();

        await segment(wrapper, 'cm').trigger('click');

        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });

    it('ONB-004 shows no option chosen while the model is not set', async () => {
        const wrapper = picker({ modelValue: undefined });

        expect(wrapper.find('[aria-checked="true"]').exists()).toBe(false);
        await segment(wrapper, 'cm').trigger('click');
        expect(wrapper.emitted('update:modelValue')).toEqual([['cm']]);
    });

    it('ONB-004 disables one option, or the whole group', () => {
        const some = picker();
        const all = picker({ disabled: true });

        expect(some.findAll('[role="radio"]').map((radio) => radio.attributes('disabled') !== undefined)).toEqual([false, false, true]);
        expect(all.findAll('[role="radio"]').every((radio) => radio.attributes('disabled') !== undefined)).toBe(true);
    });

    it('VIEW-004 has a gallery entry', () => {
        const gallery = mount(GalleryView);

        expect(gallery.findAll('h2').map((h) => h.text())).toContain('SegmentedPicker');
    });
});
