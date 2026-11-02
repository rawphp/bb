import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { toneClasses } from '@/design/tones';
import { toneIcons } from '@/design/toneIcons';
import ContinueHint from './ContinueHint.vue';
import NumberField from './NumberField.vue';
import OnboardingCallout from './OnboardingCallout.vue';
import StepHeading from './StepHeading.vue';
import StepNote from './StepNote.vue';
import StepPanel from './StepPanel.vue';
import TimeOfDayPicker from './TimeOfDayPicker.vue';
import WeekdayPicker from './WeekdayPicker.vue';
import YesNoQuestion from './YesNoQuestion.vue';

describe('OnboardingCallout', () => {
    it('UI-030 shows its tone with an icon as well as colour', () => {
        const wrapper = mount(OnboardingCallout, { props: { text: 'Get medical clearance first.', tone: 'warning' } });

        expect(wrapper.text()).toBe('Get medical clearance first.');
        expect(wrapper.classes()).toEqual(expect.arrayContaining(toneClasses.warning.split(' ')));
        expect(wrapper.get('svg path').attributes('d')).toBe(toneIcons.warning);
        expect(wrapper.get('svg').attributes('aria-hidden')).toBe('true');
    });

    it('UI-030 is an info note when the server names no tone', () => {
        const wrapper = mount(OnboardingCallout, { props: { text: 'No medical diets.' } });

        expect(wrapper.get('svg path').attributes('d')).toBe(toneIcons.info);
    });
});

describe('YesNoQuestion', () => {
    it('ONB-021 asks the question with its detail and a Yes and a No row', () => {
        const wrapper = mount(YesNoQuestion, { props: { question: 'Have you finished a fat-loss diet recently?', detail: 'In the past 8 weeks', modelValue: null } });

        expect(wrapper.get('h3').text()).toBe('Have you finished a fat-loss diet recently?');
        expect(wrapper.text()).toContain('In the past 8 weeks');
        const rows = wrapper.findAll('[role="radio"]');
        expect(rows.map((row) => row.text())).toEqual(['Yes', 'No']);
        expect(rows.map((row) => row.attributes('aria-checked'))).toEqual(['false', 'false']);
        expect(wrapper.get('[role="radiogroup"]').attributes('aria-label')).toBe('Have you finished a fat-loss diet recently?');
    });

    it('ONB-006 marks the saved answer and emits the one picked', async () => {
        const wrapper = mount(YesNoQuestion, { props: { question: 'Do you take a GLP-1?', modelValue: false } });

        expect(wrapper.findAll('[role="radio"]').map((row) => row.attributes('aria-checked'))).toEqual(['false', 'true']);
        await wrapper.findAll('[role="radio"]')[0]!.trigger('click');

        expect(wrapper.emitted('update:modelValue')).toEqual([[true]]);
    });

    it('UI-006 locks both rows while disabled', () => {
        const wrapper = mount(YesNoQuestion, { props: { question: 'Q', modelValue: null, disabled: true } });

        expect(wrapper.findAll('[role="radio"]').every((row) => row.attributes('disabled') !== undefined)).toBe(true);
    });
});

describe('ContinueHint', () => {
    it('UI-007 says why Next is off, and shows nothing without a reason', () => {
        expect(mount(ContinueHint, { props: { text: 'Fill in every field to continue.' } }).text()).toBe('Fill in every field to continue.');
        expect(mount(ContinueHint, { props: { text: null } }).find('p').exists()).toBe(false);
    });
});

describe('StepPanel', () => {
    it('UI-007 holds one repeated item of a step in a rounded panel', () => {
        const wrapper = mount(StepPanel, { slots: { default: '<p>Monday</p>' } });

        expect(wrapper.text()).toBe('Monday');
        expect(wrapper.classes()).toEqual(expect.arrayContaining(['rounded-xl', 'bg-background']));
    });
});

describe('StepHeading and StepNote', () => {
    it('UI-030 a heading is a heading, and a note is small grey text', () => {
        expect(mount(StepHeading, { props: { text: 'Tick anything that applies to you.' } }).get('h3').text()).toBe('Tick anything that applies to you.');
        const note = mount(StepNote, { props: { text: 'Accuracy isn\'t critical.' } });
        expect(note.get('p').text()).toBe('Accuracy isn\'t critical.');
        expect(note.get('p').classes()).toContain('text-secondary-text');
    });
});

describe('NumberField', () => {
    // Bound as v-model is: each emitted value comes back as the prop.
    function field(props: Record<string, unknown> = {}) {
        const wrapper = mount(NumberField, {
            props: { label: 'Weight', unit: 'kg', modelValue: null, 'onUpdate:modelValue': (value: number | null) => wrapper.setProps({ modelValue: value }), ...props },
            attachTo: document.body,
        });
        return wrapper;
    }

    it('ONB-004 shows the number in the unit on screen, up to 2 decimals', () => {
        const wrapper = field({ modelValue: 176.4 });
        const input = wrapper.get<HTMLInputElement>('input');

        expect(input.element.value).toBe('176.4');
        expect(input.attributes('aria-label')).toBe('Weight in kg');
        expect(input.attributes('inputmode')).toBe('decimal');
        expect(wrapper.text()).toContain('kg');
        expect(field({ modelValue: 80 }).get<HTMLInputElement>('input').element.value).toBe('80');
        expect(field({ modelValue: 70.126 }).get<HTMLInputElement>('input').element.value).toBe('70.13');
        wrapper.unmount();
    });

    it('ONB-004 sends each keystroke as a number, reading a comma as the decimal point', async () => {
        const wrapper = field();
        const input = wrapper.get('input');

        await input.setValue('72,5');
        await input.setValue('');
        await input.setValue('abc');

        expect(wrapper.emitted('update:modelValue')).toEqual([[72.5], [null]]);
        wrapper.unmount();
    });

    it('ONB-008 waits for the field to lose focus when it does not commit while typing', async () => {
        const wrapper = field({ commitsWhileTyping: false });
        const input = wrapper.get('input');

        await input.setValue('68');
        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
        await input.trigger('blur');

        expect(wrapper.emitted('update:modelValue')).toEqual([[68]]);
        wrapper.unmount();
    });

    it('ONB-004 follows a value another field moved, but never under the user\'s typing', async () => {
        const wrapper = field({ modelValue: 80 });
        const input = wrapper.get<HTMLInputElement>('input');

        await wrapper.setProps({ modelValue: 176.37 });
        expect(input.element.value).toBe('176.37');

        input.element.focus();
        await input.setValue('17');
        await wrapper.setProps({ modelValue: 99 });
        expect(input.element.value).toBe('17');
        wrapper.unmount();
    });

    it('ONB-004 keeps the number typed when it is left, while the parent still holds the old one', async () => {
        const wrapper = mount(NumberField, { props: { label: 'Weight', unit: 'kg', modelValue: 80, commitsWhileTyping: false }, attachTo: document.body });
        const input = wrapper.get<HTMLInputElement>('input');

        await input.setValue('81.50');
        await input.trigger('blur');
        expect(wrapper.emitted('update:modelValue')).toEqual([[81.5]]);
        expect(input.element.value).toBe('81.5');

        await input.setValue('');
        await input.trigger('blur');
        expect(input.element.value).toBe('80');
        wrapper.unmount();
    });

    it('UI-030 dims and locks a disabled field', () => {
        const wrapper = field({ disabled: true });

        expect(wrapper.get('input').attributes('disabled')).toBeDefined();
        expect(wrapper.classes()).toContain('opacity-50');
        wrapper.unmount();
    });
});

describe('WeekdayPicker', () => {
    const days = [
        { weekday: 1, name: 'Mon', full_name: 'Monday', is_on: true },
        { weekday: 2, name: 'Tue', full_name: 'Tuesday', is_on: false },
    ];

    it('ONB-015 shows each weekday the server sends as a toggle, and emits the one tapped', async () => {
        const wrapper = mount(WeekdayPicker, { props: { days } });
        const buttons = wrapper.findAll('button');

        expect(buttons.map((b) => b.text())).toEqual(['Mon', 'Tue']);
        expect(buttons.map((b) => b.attributes('aria-label'))).toEqual(['Monday', 'Tuesday']);
        expect(buttons.map((b) => b.attributes('aria-pressed'))).toEqual(['true', 'false']);
        await buttons[1]!.trigger('click');

        expect(wrapper.emitted('toggle')).toEqual([[2]]);
    });
});

describe('TimeOfDayPicker', () => {
    it('ONB-011 shows minutes after midnight as a time, and emits the time picked in minutes', async () => {
        const wrapper = mount(TimeOfDayPicker, { props: { label: 'Start', modelValue: 7 * 60 + 5 } });
        const input = wrapper.get<HTMLInputElement>('input[type="time"]');

        expect(input.element.value).toBe('07:05');
        expect(wrapper.get('label').text()).toContain('Start');
        await input.setValue('18:30');

        expect(wrapper.emitted('update:modelValue')).toEqual([[18 * 60 + 30]]);
    });

    it('ONB-011 ignores a cleared time', async () => {
        const wrapper = mount(TimeOfDayPicker, { props: { label: 'Start', modelValue: 0 } });

        await wrapper.get('input').setValue('');

        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });
});
