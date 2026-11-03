import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import GalleryView from '@/features/dev/GalleryView.vue';
import TimeField from './TimeField.vue';

function field(props: Record<string, unknown> = {}, attrs: Record<string, unknown> = {}) {
    return mount(TimeField, { props: { label: 'Start time', modelValue: '07:05', ...props }, attrs });
}

describe('TimeField', () => {
    it('ONB-011 shows a labelled 24-hour time', () => {
        const wrapper = field();

        expect(wrapper.get('label').text()).toBe('Start time');
        expect(wrapper.get<HTMLInputElement>('input[type="time"]').element.value).toBe('07:05');
    });

    it('ONB-011 updates the model once a time is picked, not on every keystroke', async () => {
        const wrapper = field();
        const input = wrapper.get<HTMLInputElement>('input');

        input.element.value = '18:30';
        await input.trigger('input');
        expect(wrapper.emitted('update:modelValue')).toBeUndefined();

        await input.trigger('change');
        expect(wrapper.emitted('update:modelValue')).toEqual([['18:30']]);
    });

    it('ONB-011 can be disabled', () => {
        expect(field().get('input').attributes('disabled')).toBeUndefined();
        expect(field({ disabled: true }).get('input').attributes('disabled')).toBeDefined();
    });

    it('ONB-011 keeps the caller\'s classes on the row and its other attributes on the input', () => {
        const wrapper = field({}, { class: 'px-4', 'data-test': 'window-start', name: 'time', 'aria-label': 'Monday starts' });

        expect(wrapper.get('label').classes()).toContain('px-4');
        const input = wrapper.get('input');
        expect(input.attributes('data-test')).toBe('window-start');
        expect(input.attributes('name')).toBe('time');
        expect(input.attributes('aria-label')).toBe('Monday starts');
    });

    it('VIEW-004 has a gallery entry', () => {
        const gallery = mount(GalleryView);

        expect(gallery.findAll('h2').map((h) => h.text())).toContain('TimeField');
    });
});
