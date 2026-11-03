import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, writeNow } from '@/app/call';
import type { ExercisesShowOutput } from '@/contracts/exercises_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import CustomExerciseSheet from './CustomExerciseSheet.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), writeNow: vi.fn() }));

const writeMock = vi.mocked(writeNow);
const choices = screenFixture<ExercisesShowOutput>('exercises_show');
const landmine = { id: '01JA8Q0V3K2M4N6P8R0T2W4Y6K', name: 'Landmine Press (my gym)', muscle_group: 'frontDelts', equipment: 'barbell', load_type: 'barbell' } as const;

type Props = { open?: boolean; reload?: (options?: { fresh?: boolean }) => Promise<void>; exercise?: typeof landmine | null };

function show(props: Props = {}) {
    return mount(CustomExerciseSheet, { props: { open: true, reload: vi.fn().mockResolvedValue(undefined), choices, ...props }, attachTo: document.body });
}

function field<T extends HTMLElement = HTMLInputElement>(name: string): T {
    return document.body.querySelector<T>(`[name="${name}"]`)!;
}

async function type(name: string, value: string): Promise<void> {
    const el = field<HTMLInputElement | HTMLSelectElement>(name);
    el.value = value;
    el.dispatchEvent(new Event(el instanceof HTMLSelectElement ? 'change' : 'input'));
    await flushPromises();
}

function save(): HTMLButtonElement {
    return document.body.querySelector<HTMLButtonElement>('[data-save]')!;
}

describe('CustomExerciseSheet', () => {
    beforeEach(() => writeMock.mockReset().mockResolvedValue({}));
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('EX-004 EX-005 a new exercise asks for a name, muscle group, equipment and load type, with the server\'s choices', () => {
        const wrapper = show();

        expect(document.body.querySelector('[role="dialog"] h2')!.textContent).toBe('New exercise');
        expect(field('name').value).toBe('');
        const options = (name: string) => [...field<HTMLSelectElement>(name).options].map((o) => [o.value, o.text]);
        expect(options('muscle_group')).toEqual([['', 'Choose'], ...choices.muscle_groups.map((c) => [c.value, c.label])]);
        expect(options('equipment')).toEqual([['', 'Choose'], ...choices.equipment.map((c) => [c.value, c.label])]);
        expect(options('load_type')).toEqual([['', 'Choose'], ...choices.load_types.map((c) => [c.value, c.label])]);
        expect(options('load_type')).toContainEqual(['bodyweightPlusLoad', 'Bodyweight plus load']);
        wrapper.unmount();
    });

    it('EX-004 Save stays off until the name and all three choices are filled in', async () => {
        const wrapper = show();

        expect(save().disabled).toBe(true);
        await type('name', 'Seal row');
        await type('muscle_group', 'back');
        await type('equipment', 'dumbbell');
        expect(save().disabled).toBe(true);
        await type('load_type', 'dumbbell');
        expect(save().disabled).toBe(false);
        await type('muscle_group', '');
        expect(save().disabled).toBe(true);
        await type('muscle_group', 'back');
        await type('equipment', '');
        expect(save().disabled).toBe(true);
        await type('equipment', 'dumbbell');
        await type('name', '   ');
        expect(save().disabled).toBe(true);
        wrapper.unmount();
    });

    it('EX-004 CAP-019 saves a new exercise with no id, for the server to assign, and the trimmed name', async () => {
        const reload = vi.fn().mockResolvedValue(undefined);
        const wrapper = show({ reload });

        await type('name', '  Seal row  ');
        await type('muscle_group', 'back');
        await type('equipment', 'dumbbell');
        await type('load_type', 'dumbbell');
        save().click();
        await flushPromises();

        const [name, input] = writeMock.mock.calls[0]!;
        expect(name).toBe('custom_exercise_save');
        expect(input).toEqual({
            name: 'Seal row',
            muscle_group: 'back',
            equipment: 'dumbbell',
            load_type: 'dumbbell',
        });
        expect(inputViolations('custom_exercise_save', input)).toEqual([]);
        expect(reload).toHaveBeenCalled();
        expect(wrapper.emitted('saved')).toEqual([[]]);
        wrapper.unmount();
    });

    it('EX-004 editing opens with the exercise filled in and saves it under its own id', async () => {
        const wrapper = show({ exercise: landmine });

        expect(document.body.querySelector('[role="dialog"] h2')!.textContent).toBe('Edit exercise');
        expect(field('name').value).toBe('Landmine Press (my gym)');
        expect(field<HTMLSelectElement>('muscle_group').value).toBe('frontDelts');
        await type('load_type', 'machine');
        save().click();
        await flushPromises();

        const input = writeMock.mock.calls[0]![1];
        expect(input).toEqual({ exercise_id: landmine.id, name: 'Landmine Press (my gym)', muscle_group: 'frontDelts', equipment: 'barbell', load_type: 'machine' });
        expect(inputViolations('custom_exercise_save', input)).toEqual([]);
        wrapper.unmount();
    });

    it('CAP-019 a new exercise opened after an edit starts blank and is sent with no id', async () => {
        const wrapper = show({ exercise: landmine });

        save().click();
        await flushPromises();
        await wrapper.setProps({ open: false, exercise: null });
        await wrapper.setProps({ open: true });

        expect(document.body.querySelector('[role="dialog"] h2')!.textContent).toBe('New exercise');
        expect(field('name').value).toBe('');
        expect(field<HTMLSelectElement>('muscle_group').value).toBe('');
        await type('name', 'Pendlay row');
        await type('muscle_group', 'back');
        await type('equipment', 'barbell');
        await type('load_type', 'barbell');
        save().click();
        await flushPromises();

        const [edit, create] = writeMock.mock.calls.map((call) => call[1] as Record<string, unknown>);
        expect(edit).toMatchObject({ exercise_id: landmine.id });
        expect(create).toEqual({ name: 'Pendlay row', muscle_group: 'back', equipment: 'barbell', load_type: 'barbell' });
        expect(inputViolations('custom_exercise_save', create)).toEqual([]);
        wrapper.unmount();
    });

    it('EX-004 an edit that was cancelled reopens with the exercise\'s own values', async () => {
        const wrapper = show({ exercise: landmine });

        await type('name', 'Something else');
        document.body.querySelector<HTMLButtonElement>('[data-cancel]')!.click();
        await wrapper.setProps({ open: false });
        await wrapper.setProps({ open: true });

        expect(field('name').value).toBe('Landmine Press (my gym)');
        wrapper.unmount();
    });

    it('CAP-014 a save with no final answer reads the screen underneath from the server and stays open', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('custom_exercise_save', 502, null));
        const reload = vi.fn().mockResolvedValue(undefined);
        const wrapper = show({ exercise: landmine, reload });

        save().click();
        await flushPromises();

        expect(reload).toHaveBeenCalledWith({ fresh: true });
        expect(wrapper.emitted('saved')).toBeUndefined();
        expect(document.body.querySelector('[role="dialog"] [role="alert"]')!.textContent).toContain('may have worked');
        wrapper.unmount();
    });

    it('CAP-012 a refused save keeps the sheet open with the server\'s words', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('custom_exercise_save', 422, { error: { message: 'You already have an exercise with that name.' } }));
        const wrapper = show({ exercise: landmine });

        save().click();
        await flushPromises();

        expect(document.body.querySelector('[role="dialog"] [role="alert"]')!.textContent).toContain('You already have an exercise with that name.');
        expect(wrapper.emitted('saved')).toBeUndefined();
        wrapper.unmount();
    });

    it('EX-004 Cancel closes without saving', async () => {
        const wrapper = show();

        document.body.querySelector<HTMLButtonElement>('[data-cancel]')!.click();
        await flushPromises();

        expect(wrapper.emitted('cancel')).toHaveLength(1);
        expect(writeMock).not.toHaveBeenCalled();
        wrapper.unmount();
    });
});
