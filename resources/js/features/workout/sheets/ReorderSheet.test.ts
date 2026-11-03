import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { WorkoutShowOutput } from '@/contracts/workout_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import type { WorkoutWrite } from '../workoutWrite';
import ReorderSheet from './ReorderSheet.vue';

const BENCH = '01JB8W3Q000000000000000010';
const ROW = '01JB8W3Q000000000000000011';
const payload = () => screenFixture<WorkoutShowOutput>('workout_show');

const show = (data = payload()) => mount(ReorderSheet, { props: { payload: data }, global: { stubs: { teleport: true } } });

function sends(wrapper: ReturnType<typeof show>): Record<string, unknown>[] {
    return (wrapper.emitted('send') ?? []).map(([change]) => {
        const { name, input } = change as WorkoutWrite;
        expect(name).toBe('workout_exercise_reorder');
        expect(inputViolations(name, input)).toEqual([]);
        return input as Record<string, unknown>;
    });
}

describe('ReorderSheet', () => {
    it('WO-031 lists the exercises in order with Move up and Move down, off where the server says', () => {
        const wrapper = show();

        expect(wrapper.get('[role="dialog"] h2').text()).toBe('Reorder exercises');
        expect(wrapper.findAll('[data-exercise]').map((row) => row.get('[data-test="name"]').text())).toEqual(['Bench Press', 'Barbell Row']);
        expect(wrapper.get('[aria-label="Move Bench Press up"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[aria-label="Move Bench Press down"]').attributes('disabled')).toBeUndefined();
        expect(wrapper.get('[aria-label="Move Barbell Row up"]').attributes('disabled')).toBeUndefined();
        expect(wrapper.get('[aria-label="Move Barbell Row down"]').attributes('disabled')).toBeDefined();
    });

    it('WO-031 each tap sends one move, counting positions from 1', async () => {
        const data = payload();
        Object.assign(data.exercises[0]!, { can_move_down: true });
        const wrapper = show(data);

        await wrapper.get('[aria-label="Move Bench Press down"]').trigger('click');
        await wrapper.get('[aria-label="Move Barbell Row up"]').trigger('click');

        expect(sends(wrapper)).toEqual([
            { workout_exercise_id: BENCH, position: 2, remaining_weeks: false },
            { workout_exercise_id: ROW, position: 1, remaining_weeks: false },
        ]);
    });

    it('MESO-045 with Apply to all remaining weeks on, the move says so', async () => {
        const wrapper = show();

        await wrapper.get('[role="checkbox"]').trigger('click');
        await wrapper.get('[aria-label="Move Barbell Row up"]').trigger('click');

        expect(sends(wrapper)).toEqual([{ workout_exercise_id: ROW, position: 1, remaining_weeks: true }]);
    });

    it('WO-031 Done closes the sheet', async () => {
        const wrapper = show();

        await wrapper.get('[data-cancel]').trigger('click');

        expect(wrapper.get('[data-cancel]').text()).toBe('Done');
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('WO-031 a workout with no exercises says so (workout_show.empty)', () => {
        const wrapper = show(screenFixture<WorkoutShowOutput>('workout_show.empty'));

        expect(wrapper.find('[data-exercise]').exists()).toBe(false);
        expect(wrapper.get('[data-test="none"]').text()).toBe('This workout has no exercises.');
    });
});
