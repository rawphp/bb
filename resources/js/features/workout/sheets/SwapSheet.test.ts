import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read } from '@/app/call';
import type { ExercisesShowOutput } from '@/contracts/exercises_show';
import type { WorkoutShowOutput } from '@/contracts/workout_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import type { WorkoutWrite } from '../workoutWrite';
import SwapSheet from './SwapSheet.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));

const readMock = vi.mocked(read);
const library = screenFixture<ExercisesShowOutput>('exercises_show');
const workout = screenFixture<WorkoutShowOutput>('workout_show');
const BENCH = '01JB8W3Q000000000000000010';

function show(props: Record<string, unknown> = {}) {
    return mount(SwapSheet, { props: { payload: workout, exerciseId: BENCH, library, ...props }, global: { stubs: { teleport: true } } });
}

const rows = (wrapper: ReturnType<typeof show>) => wrapper.findAll('[data-test="exercise"]');

describe('SwapSheet', () => {
    beforeEach(() => {
        readMock.mockReset().mockReturnValue(new Promise(() => {}));
    });

    it('EX-008 gives the swap advice and lists the library exercises of the same muscle', () => {
        const wrapper = show();

        expect(wrapper.get('[role="dialog"] h2').text()).toBe('Swap exercise');
        expect(wrapper.get('[data-test="advice"]').text()).toBe(workout.swap_advice);
        expect(rows(wrapper).map((row) => row.get('[data-test="name"]').text())).toEqual(['Assisted Chest Dip', 'Barbell Bench Press', 'Cable Fly']);
        expect(rows(wrapper)[0]!.text()).toContain('Chest · Machine');
    });

    it('EX-008 never offers the exercise being swapped out', () => {
        const current = library.exercises.find((found) => found.name === 'Cable Fly')!;
        const data = structuredClone(workout);
        data.exercises[0]!.exercise_id = current.id;
        const wrapper = show({ payload: data });

        expect(rows(wrapper).map((row) => row.get('[data-test="name"]').text())).toEqual(['Assisted Chest Dip', 'Barbell Bench Press']);
    });

    it('EX-008 says when no other exercise trains the muscle (exercises_show.empty)', () => {
        const wrapper = show({ library: screenFixture<ExercisesShowOutput>('exercises_show.empty') });

        expect(wrapper.get('[data-test="none"]').text()).toBe('No exercises');
    });

    it('EX-008 offers This session only, chosen, and the rest of the mesocycle', () => {
        const wrapper = show();

        const radios = wrapper.get('[role="radiogroup"][aria-label="Swap scope"]').findAll('[role="radio"]');
        expect(radios.map((radio) => radio.attributes('aria-label'))).toEqual(['This session only', 'Rest of the mesocycle']);
        expect(radios[0]!.attributes('aria-checked')).toBe('true');
    });

    it('EX-008 a tap swaps for this session, under a new id, and closes (CAP-019)', async () => {
        const wrapper = show();

        await rows(wrapper)[1]!.trigger('click');

        const [change] = wrapper.emitted('send')![0] as [WorkoutWrite];
        expect(change.name).toBe('exercise_swap');
        expect(inputViolations(change.name, change.input)).toEqual([]);
        expect(change.input).toEqual({ workout_exercise_id: BENCH, new_workout_exercise_id: expect.any(String), exercise_id: library.exercises.find((found) => found.name === 'Barbell Bench Press')!.id, scope: 'this_session' });
        expect(change.creates).toEqual([change.input.new_workout_exercise_id]);
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('EX-008 the rest of the mesocycle swaps every week', async () => {
        const wrapper = show();

        await wrapper.get('[role="radio"][aria-label="Rest of the mesocycle"]').trigger('click');
        await rows(wrapper)[0]!.trigger('click');

        const [change] = wrapper.emitted('send')![0] as [WorkoutWrite];
        expect(inputViolations(change.name, change.input)).toEqual([]);
        expect(change.input.scope).toBe('rest_of_mesocycle');
    });

    it('CAP-002 reads the library when it is not handed one, with a skeleton until it comes', () => {
        const wrapper = show({ library: undefined });

        expect(readMock).toHaveBeenCalledWith('exercises_show', {}, expect.any(Function), expect.any(Function));
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
    });

    it('CAP-004 says when the library could not load, and Try again reads again', async () => {
        readMock.mockRejectedValue(new CapabilityError('exercises_show', 500, null));
        const wrapper = show({ library: undefined });
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('The exercise library could not be loaded.');
        await wrapper.get('[data-test="retry"]').trigger('click');
        await flushPromises();

        expect(readMock).toHaveBeenCalledTimes(2);
    });

    it('EX-008 Cancel closes without sending', async () => {
        const wrapper = show();

        await wrapper.get('[data-cancel]').trigger('click');

        expect(wrapper.emitted('send')).toBeUndefined();
        expect(wrapper.emitted('close')).toHaveLength(1);
    });
});
