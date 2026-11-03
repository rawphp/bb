import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { WorkoutShowOutput } from '@/contracts/workout_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import type { WorkoutWrite } from '../workoutWrite';
import SetMenuSheet from './SetMenuSheet.vue';

const BENCH = '01JB8W3Q000000000000000010';
const SET_1 = '01JB8W3Q000000000000000100';
const payload = () => screenFixture<WorkoutShowOutput>('workout_show');

function show(data = payload(), setId = SET_1) {
    return mount(SetMenuSheet, { props: { payload: data, setId }, global: { stubs: { teleport: true } } });
}

type Wrapper = ReturnType<typeof show>;

function sent(wrapper: Wrapper, at = 0): WorkoutWrite {
    const [change] = wrapper.emitted('send')![at] as [WorkoutWrite];
    expect(inputViolations(change.name, change.input), change.name).toEqual([]);
    return change;
}

describe('SetMenuSheet', () => {
    it('WO-020 is titled with the set and its exercise, and lists the set actions then the set types', () => {
        const wrapper = show();

        expect(wrapper.get('[role="dialog"] h2').text()).toBe('Set 1 · Bench Press');
        expect(wrapper.findAll('[data-test="action"]').map((row) => row.text())).toEqual(['Add set below', 'Skip set', 'Delete set']);
        expect(wrapper.get('[data-test="delete"]').classes().join(' ')).toContain('tone-warning');
        expect(wrapper.text()).toContain('Set type');
        expect(wrapper.get('[data-save]').text()).toBe('Done');
    });

    it('WO-020 Add set below adds a set of its own type after this one, under a new id (CAP-019), and closes', async () => {
        const wrapper = show();

        await wrapper.get('[data-test="add"]').trigger('click');

        const change = sent(wrapper);
        expect(change.name).toBe('workout_set_add');
        expect(change.input).toMatchObject({ workout_exercise_id: BENCH, after_set_id: SET_1, set_type: 'regular_straight' });
        expect(change.creates).toEqual([change.input.set_id]);
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('WO-020 Skip set skips it, and a skipped set reads Unskip set and unskips', async () => {
        const wrapper = show();
        await wrapper.get('[data-test="skip"]').trigger('click');
        expect(sent(wrapper).input).toEqual({ set_id: SET_1, is_skipped: true });

        const data = payload();
        data.exercises[0]!.sets[0]!.is_skipped = true;
        const skipped = show(data);
        expect(skipped.get('[data-test="skip"]').text()).toBe('Unskip set');
        await skipped.get('[data-test="skip"]').trigger('click');

        expect(sent(skipped).input).toEqual({ set_id: SET_1, is_skipped: false });
    });

    it('WO-020 Delete set asks first: the write carries its question', async () => {
        const wrapper = show();

        await wrapper.get('[data-test="delete"]').trigger('click');

        const change = sent(wrapper);
        expect(change.name).toBe('workout_set_delete');
        expect(change.input).toEqual({ set_id: SET_1 });
        expect(change.confirm).toEqual({ title: 'Delete set 1?', message: 'Whatever was logged on it goes with it. It cannot be undone.', action: 'Delete set' });
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it("WO-021 lists the server's set types with the set's own chosen, and Done waits for another", () => {
        const wrapper = show();

        const radios = wrapper.get('[role="radiogroup"][aria-label="Set type"]').findAll('[role="radio"]');
        expect(radios.map((radio) => radio.attributes('aria-label'))).toEqual(payload().set_type_choices.map((choice) => choice.label));
        expect(radios.filter((radio) => radio.attributes('aria-checked') === 'true').map((radio) => radio.attributes('aria-label'))).toEqual(['Regular · Straight']);
        expect(wrapper.text()).toContain('Rest 5 to 15 seconds between mini-sets, and log the total reps.');
        expect(wrapper.get('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('WO-021 choosing another type and tapping Done changes the type, and closes', async () => {
        const wrapper = show();

        await wrapper.get('[role="radio"][aria-label="Myorep (M)"]').trigger('click');
        expect(wrapper.get('[data-save]').attributes('disabled')).toBeUndefined();
        await wrapper.get('[data-save]').trigger('click');

        const change = sent(wrapper);
        expect(change.name).toBe('workout_set_type_set');
        expect(change.input).toEqual({ set_id: SET_1, set_type: 'myorep' });
        expect(change.label).toBe('Made Bench Press set 1 Myorep (M)');
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('WO-021 choosing the type it already has leaves Done off', async () => {
        const wrapper = show();

        await wrapper.get('[role="radio"][aria-label="Myorep (M)"]').trigger('click');
        await wrapper.get('[role="radio"][aria-label="Regular · Straight"]').trigger('click');

        expect(wrapper.get('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('WO-020 Cancel closes without sending', async () => {
        const wrapper = show();

        await wrapper.get('[data-cancel]').trigger('click');

        expect(wrapper.emitted('send')).toBeUndefined();
        expect(wrapper.emitted('close')).toHaveLength(1);
    });
});
