import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read } from '@/app/call';
import type { ExercisesShowOutput } from '@/contracts/exercises_show';
import type { WorkoutShowOutput } from '@/contracts/workout_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import type { WorkoutWrite } from '../workoutWrite';
import ExercisePickerSheet from './ExercisePickerSheet.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));

const readMock = vi.mocked(read);
const library = screenFixture<ExercisesShowOutput>('exercises_show');
const workout = screenFixture<WorkoutShowOutput>('workout_show');

function show(props: Record<string, unknown> = { payload: workout, library }) {
    return mount(ExercisePickerSheet, { props, global: { stubs: { teleport: true } } });
}

const rows = (wrapper: ReturnType<typeof show>) => wrapper.findAll('[data-test="exercise"]');

describe('ExercisePickerSheet', () => {
    beforeEach(() => {
        readMock.mockReset().mockReturnValue(new Promise(() => {}));
    });

    it('WO-031 lists the whole library, each exercise with its muscle and equipment', () => {
        const wrapper = show();

        expect(wrapper.get('[role="dialog"] h2').text()).toBe('Add exercise');
        expect(rows(wrapper)).toHaveLength(library.exercises.length);
        expect(rows(wrapper)[0]!.text()).toContain('45-Degree Hip Extension');
        expect(rows(wrapper)[0]!.text()).toContain('Hamstrings · Machine');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('EX-008 the search field filters the list by name', async () => {
        const wrapper = show();

        await wrapper.get('input[aria-label="Search exercises"]').setValue('bench');

        expect(rows(wrapper).map((row) => row.get('[data-test="name"]').text())).toEqual(['Barbell Bench Press']);
    });

    it('WO-031 says when the library has no exercises (exercises_show.empty)', () => {
        const wrapper = show({ payload: workout, library: screenFixture<ExercisesShowOutput>('exercises_show.empty') });

        expect(wrapper.get('[data-test="none"]').text()).toBe('No exercises');
        expect(rows(wrapper)).toHaveLength(0);
    });

    it('CAP-002 reads the library when it is not handed one, with a skeleton until it comes', () => {
        const wrapper = show({ payload: workout });

        expect(readMock).toHaveBeenCalledWith('exercises_show', {}, expect.any(Function), expect.any(Function));
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
    });

    it('CAP-004 says when the library could not load, and Try again reads again', async () => {
        readMock.mockRejectedValue(new CapabilityError('exercises_show', 500, null));
        const wrapper = show({ payload: workout });
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('The exercise library could not be loaded.');
        await wrapper.get('[data-test="retry"]').trigger('click');
        await flushPromises();

        expect(readMock).toHaveBeenCalledTimes(2);
    });

    it('WO-031 a tap adds that exercise to this session only, under a new id, and closes (CAP-019)', async () => {
        const wrapper = show();

        await rows(wrapper)[1]!.trigger('click');

        const [change] = wrapper.emitted('send')![0] as [WorkoutWrite];
        expect(change.name).toBe('workout_exercise_add');
        expect(inputViolations(change.name, change.input)).toEqual([]);
        expect(change.input).toEqual({ workout_id: workout.workout_id, workout_exercise_id: expect.any(String), exercise_id: library.exercises[1]!.id, remaining_weeks: false });
        expect(change.creates).toEqual([change.input.workout_exercise_id]);
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('MESO-045 with Apply to all remaining weeks on, the add says so', async () => {
        const wrapper = show();

        await wrapper.get('[role="checkbox"]').trigger('click');
        await rows(wrapper)[0]!.trigger('click');

        const [change] = wrapper.emitted('send')![0] as [WorkoutWrite];
        expect(inputViolations(change.name, change.input)).toEqual([]);
        expect(change.input.remaining_weeks).toBe(true);
    });

    it('WO-031 Cancel closes without sending', async () => {
        const wrapper = show();

        await wrapper.get('[data-cancel]').trigger('click');

        expect(wrapper.emitted('send')).toBeUndefined();
        expect(wrapper.emitted('close')).toHaveLength(1);
    });
});
