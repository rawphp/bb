import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { WorkoutShowOutput } from '@/contracts/workout_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import type { WorkoutWrite } from '../workoutWrite';
import RelabelSheet from './RelabelSheet.vue';

const payload = screenFixture<WorkoutShowOutput>('workout_show');

const show = () => mount(RelabelSheet, { props: { payload }, global: { stubs: { teleport: true } } });

describe('RelabelSheet', () => {
    it('WO-031 shows the day name to rename, with what renaming does', () => {
        const wrapper = show();

        expect(wrapper.get('[role="dialog"] h2').text()).toBe('Relabel day');
        expect(wrapper.get<HTMLInputElement>('input[aria-label="Day name"]').element.value).toBe(payload.day_label);
        expect(wrapper.text()).toContain('Renames this day in the mesocycle.');
    });

    it('WO-031 Save is off while the name is blank', async () => {
        const wrapper = show();

        await wrapper.get('input').setValue('   ');

        expect(wrapper.get('[data-save]').attributes('disabled')).toBeDefined();
    });

    it('WO-031 saving sends workout_day_relabel with the new name, and closes', async () => {
        const wrapper = show();

        await wrapper.get('input').setValue('Push day');
        await wrapper.get('[data-save]').trigger('click');

        const [change] = wrapper.emitted('send')![0] as [WorkoutWrite];
        expect(change.name).toBe('workout_day_relabel');
        expect(change.input).toEqual({ workout_id: payload.workout_id, label: 'Push day' });
        expect(inputViolations(change.name, change.input)).toEqual([]);
        expect(wrapper.emitted('close')).toHaveLength(1);
    });
});
