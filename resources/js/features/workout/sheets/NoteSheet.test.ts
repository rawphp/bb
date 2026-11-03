import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { WorkoutShowOutput } from '@/contracts/workout_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import type { WorkoutWrite } from '../workoutWrite';
import NoteSheet from './NoteSheet.vue';

const ROW = '01JB8W3Q000000000000000011';
const BENCH = '01JB8W3Q000000000000000010';
const payload = () => screenFixture<WorkoutShowOutput>('workout_show.warning');

function show(props: Record<string, unknown>) {
    return mount(NoteSheet, { props: { payload: payload(), exerciseId: ROW, ...props }, global: { stubs: { teleport: true } } });
}

function sent(wrapper: ReturnType<typeof show>): WorkoutWrite {
    const [change] = wrapper.emitted('send')![0] as [WorkoutWrite];
    expect(inputViolations(change.name, change.input), change.name).toEqual([]);
    return change;
}

describe('NoteSheet', () => {
    it('EX-010 Pin note shows the pinned note, with a hint that it shows every time the exercise appears', () => {
        const wrapper = show({ pinned: true });

        expect(wrapper.get('[role="dialog"] h2').text()).toBe('Pin note');
        expect(wrapper.text()).toContain('Shows every time Barbell Row appears.');
        expect(wrapper.get<HTMLTextAreaElement>('textarea[aria-label="Note"]').element.value).toBe('Chest up, pull to the belly button');
    });

    it('EX-010 saving a pinned note sends exercise_note_set for the library exercise, and closes', async () => {
        const wrapper = show({ pinned: true });

        await wrapper.get('textarea').setValue('Elbows tucked');
        await wrapper.get('[data-save]').trigger('click');

        const change = sent(wrapper);
        expect(change.name).toBe('exercise_note_set');
        expect(change.input).toEqual({ exercise_id: payload().exercises[1]!.exercise_id, note: 'Elbows tucked' });
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('WO-022 Add note keeps a note with this session, seeded from the session note', () => {
        const wrapper = show({ pinned: false });

        expect(wrapper.get('[role="dialog"] h2').text()).toBe('Add note');
        expect(wrapper.text()).toContain('Kept with this session.');
        expect(wrapper.get<HTMLTextAreaElement>('textarea').element.value).toBe('Superset with face pulls');
    });

    it('WO-022 saving a session note sends workout_exercise_note_set; empty text clears it with null', async () => {
        const wrapper = show({ pinned: false });

        await wrapper.get('textarea').setValue('  ');
        await wrapper.get('[data-save]').trigger('click');

        const change = sent(wrapper);
        expect(change.name).toBe('workout_exercise_note_set');
        expect(change.input).toEqual({ workout_exercise_id: ROW, note: null });
    });

    it('WO-022 an exercise with no note starts empty', () => {
        const wrapper = show({ pinned: true, exerciseId: BENCH });

        expect(wrapper.get<HTMLTextAreaElement>('textarea').element.value).toBe('');
    });

    it('WO-022 Cancel closes without sending', async () => {
        const wrapper = show({ pinned: true });

        await wrapper.get('[data-cancel]').trigger('click');

        expect(wrapper.emitted('send')).toBeUndefined();
        expect(wrapper.emitted('close')).toHaveLength(1);
    });
});
