import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { TodayShowOutput } from '@/contracts/today_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import CheckInButton from './CheckInButton.vue';
import ManualCheckInSheet from './ManualCheckInSheet.vue';

const meal = () => screenFixture<TodayShowOutput>('today_show').meals[0]!;

describe('CheckInButton', () => {
    it.each([
        ['not_checked_in', 'false', 'text-secondary-text'],
        ['on_track', 'true', 'text-tone-good'],
        ['under', 'true', 'text-tone-warning'],
        ['over', 'true', 'text-tone-warning'],
    ] as const)('MEAL-047 shows %s with its own icon and colour', (state, checked, colour) => {
        const wrapper = mount(CheckInButton, { props: { state, label: 'Checked in', mealName: 'Lunch' } });

        const box = wrapper.get('[role="checkbox"]');
        expect(box.attributes('aria-checked')).toBe(checked);
        expect(box.attributes('aria-label')).toBe('Lunch eaten');
        expect(box.attributes('aria-description')).toBe('Checked in');
        expect(box.classes()).toContain(colour);
        expect(wrapper.find(`[data-shape="${state}"]`).exists()).toBe(true);
    });

    it('MEAL-040 a tap checks the meal in or removes the check-in', async () => {
        const wrapper = mount(CheckInButton, { props: { state: 'not_checked_in', label: 'Not checked in', mealName: 'Lunch' } });

        await wrapper.get('button').trigger('click');

        expect(wrapper.emitted('toggle')).toHaveLength(1);
    });
});

describe('ManualCheckInSheet', () => {
    function open(props: Record<string, unknown> = {}) {
        return mount(ManualCheckInSheet, { props: { meal: meal(), ...props }, attachTo: document.body, global: { stubs: { teleport: true } } });
    }

    it('MEAL-041 opens on the meal\'s time and macros, as the server sends them', () => {
        const wrapper = open();

        expect(wrapper.text()).toContain('Check in Breakfast');
        expect((wrapper.get('input[type="time"]').element as HTMLInputElement).value).toBe('08:00');
        expect((wrapper.get('input[name="kcal"]').element as HTMLInputElement).value).toBe('780');
        expect((wrapper.get('input[name="protein"]').element as HTMLInputElement).value).toBe('51');
        expect((wrapper.get('input[name="fat"]').element as HTMLInputElement).value).toBe('21');
        expect((wrapper.get('input[name="carbs"]').element as HTMLInputElement).value).toBe('96');
    });

    it('MEAL-041 saves the time and amounts as typed, in meal_check_in\'s shape', async () => {
        const wrapper = open();

        await wrapper.get('input[type="time"]').setValue('08:45');
        await wrapper.get('input[name="kcal"]').setValue('640');
        await wrapper.get('input[name="protein"]').setValue('42.5');
        await wrapper.get('[data-save]').trigger('click');

        const [input] = wrapper.emitted('save')![0] as [Record<string, unknown>];
        expect(input).toEqual({ meal_id: '01JBX0M1N2P3Q4R5S6T7V8W9X0', time: '08:45', kcal: 640, protein_g: 42.5, fat_g: 21, carbs_g: 96 });
        expect(inputViolations('meal_check_in', input)).toEqual([]);
    });

    it('MEAL-041 keeps Save off while an amount is not a number', async () => {
        const wrapper = open();

        await wrapper.get('input[name="fat"]').setValue('lots');

        expect(wrapper.get('[data-save]').attributes('disabled')).toBeDefined();
    });

    it.each([
        ['kcal', ''],
        ['protein', '-50'],
        ['carbs', '1,200'],
        ['fat', '1.200'],
        ['time', ''],
    ])('MEAL-041 keeps Save off while %s reads %j', async (field, text) => {
        const wrapper = open();

        await wrapper.get(field === 'time' ? 'input[type="time"]' : `input[name="${field}"]`).setValue(text);

        expect(wrapper.get('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('MEAL-041 saves the server\'s own amounts as sent, whatever their decimals', async () => {
        const unrounded = meal();
        unrounded.check_in.manual = { ...unrounded.check_in.manual, protein_g: 3.875, carbs_g: 70.125 };
        const wrapper = mount(ManualCheckInSheet, { props: { meal: unrounded }, attachTo: document.body, global: { stubs: { teleport: true } } });

        await wrapper.get('[data-save]').trigger('click');

        const [input] = wrapper.emitted('save')![0] as [Record<string, unknown>];
        expect(input).toMatchObject({ protein_g: 3.875, carbs_g: 70.125 });
    });

    it('MEAL-041 takes a comma as the decimal point', async () => {
        const wrapper = open();

        await wrapper.get('input[name="protein"]').setValue('42,5');
        await wrapper.get('[data-save]').trigger('click');

        expect((wrapper.emitted('save')![0] as [Record<string, unknown>])[0].protein_g).toBe(42.5);
    });

    it('CAP-014 keeps Save off while the check-in is being saved', () => {
        const wrapper = open({ busy: true });

        expect(wrapper.get('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('MEAL-041 Cancel closes without saving', async () => {
        const wrapper = open();

        await wrapper.get('[data-cancel]').trigger('click');

        expect(wrapper.emitted('cancel')).toHaveLength(1);
        expect(wrapper.emitted('save')).toBeUndefined();
    });

    it('CAP-012 shows why the server refused the check-in', () => {
        const wrapper = open({ error: 'That time is outside the day.' });

        expect(wrapper.text()).toContain('That time is outside the day.');
    });
});
