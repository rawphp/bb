import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { pendingInput } from '@/app/outbox';
import type { WorkoutShowOutput } from '@/contracts/workout_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import WorkoutSetRow from './WorkoutSetRow.vue';
import type { WorkoutWrite } from './workoutWrite';

vi.mock('@/app/outbox', async (original) => ({ ...(await original<typeof import('@/app/outbox')>()), pendingInput: vi.fn(() => undefined) }));

const workout = () => screenFixture<WorkoutShowOutput>('workout_show');

function row(change: (payload: WorkoutShowOutput) => void = () => {}, props: Record<string, unknown> = {}, number = 0) {
    const payload = workout();
    change(payload);
    const exercise = payload.exercises[0]!;
    return mount(WorkoutSetRow, {
        props: {
            set: exercise.sets[number]!,
            exercise,
            unit: payload.weight_unit,
            editable: true,
            rirInfo: payload.rir_info,
            setTypeChoices: payload.set_type_choices,
            ...props,
        },
        global: { stubs: { teleport: true, RouterLink: true } },
    });
}

const weight = (wrapper: ReturnType<typeof row>) => wrapper.get<HTMLInputElement>('[data-test="weight"]');
const reps = (wrapper: ReturnType<typeof row>) => wrapper.get<HTMLInputElement>('[data-test="reps"]');
const sends = (wrapper: ReturnType<typeof row>) =>
    (wrapper.emitted('send') ?? []).map(([change]) => {
        const { name, input } = change as WorkoutWrite;
        expect(inputViolations(name, input), name).toEqual([]);
        return input;
    });

describe('WorkoutSetRow', () => {
    beforeEach(() => {
        vi.mocked(pendingInput).mockReturnValue(undefined);
    });

    it('WO-003 shows a menu, a weight field, a reps field and a Log box', () => {
        const wrapper = row();

        expect(wrapper.get('[data-test="menu"]').attributes('aria-label')).toBe('Set 1 menu');
        expect(weight(wrapper).attributes('aria-label')).toBe('Set 1 Weight (kg)');
        expect(reps(wrapper).attributes('aria-label')).toBe('Set 1 reps');
        expect(wrapper.get('[data-test="log"]').attributes('aria-checked')).toBe('false');
    });

    it('UI-020 weight opens the decimal pad and reps the number pad', () => {
        const wrapper = row();

        expect(weight(wrapper).attributes('inputmode')).toBe('decimal');
        expect(reps(wrapper).attributes('inputmode')).toBe('numeric');
    });

    it('WO-004 pre-fills the target, with the target icon while the planned weight is in use', async () => {
        const wrapper = row();

        expect(weight(wrapper).element.value).toBe('82.5');
        expect(reps(wrapper).element.value).toBe('10');
        expect(wrapper.find('[data-test="target"]').exists()).toBe(true);

        await weight(wrapper).setValue('85');

        expect(wrapper.find('[data-test="target"]').exists()).toBe(false);
    });

    it('WO-005 CAP-016 a weight changed within the table shows its reps from the server\'s table', async () => {
        const wrapper = row();

        await weight(wrapper).setValue('87.5');
        expect(reps(wrapper).element.value).toBe('7');

        await weight(wrapper).setValue('77,5');
        expect(reps(wrapper).element.value).toBe('12');
    });

    it('CAP-016 a weight outside the table empties reps and shows the server\'s "just log reps" text', async () => {
        const wrapper = row();

        await weight(wrapper).setValue('100');

        expect(reps(wrapper).element.value).toBe('');
        expect(reps(wrapper).attributes('placeholder')).toBe('2 RIR, just log reps');
    });

    it('WO-005 reps the user typed stay as typed when the weight changes', async () => {
        const wrapper = row();

        await reps(wrapper).setValue('6');
        await weight(wrapper).setValue('87.5');

        expect(reps(wrapper).element.value).toBe('6');
    });

    it('WO-006 week 1 with no carried loads shows empty weights and the RIR target in reps', () => {
        const wrapper = row((payload) => {
            const set = payload.exercises[0]!.sets[0]!;
            Object.assign(set, { weight: null, reps: null, target_weight: null, target_text: null, rep_table: [], off_table_text: null, reps_placeholder: '3 RIR' });
        });

        expect(weight(wrapper).element.value).toBe('');
        expect(reps(wrapper).attributes('placeholder')).toBe('3 RIR');
        expect(wrapper.find('[data-test="target"]').exists()).toBe(false);
    });

    it('WO-006 a set past the first tells the card nothing', async () => {
        const wrapper = row((payload) => {
            payload.exercises[0]!.carries_first_weight = true;
        }, {}, 1);

        await weight(wrapper).setValue('60');

        expect(wrapper.emitted('weight-typed')).toBeUndefined();
    });

    it('WO-006 the first set\'s typed weight is told to the card when the server says it carries', async () => {
        const wrapper = row((payload) => {
            payload.exercises[0]!.carries_first_weight = true;
        });

        await weight(wrapper).setValue('60');

        expect(wrapper.emitted('weight-typed')!.at(-1)).toEqual(['60']);
    });

    it('WO-006 a later set with no weight of its own shows the carried weight, and can be overwritten', async () => {
        const wrapper = row(
            (payload) => {
                Object.assign(payload.exercises[0]!.sets[1]!, { weight: null, reps: null, target_weight: null, rep_table: [] });
            },
            { carriedWeight: '60' },
            1,
        );

        expect(weight(wrapper).element.value).toBe('60');
        await weight(wrapper).setValue('62.5');
        await weight(wrapper).trigger('change');

        expect(sends(wrapper)).toEqual([{ set_id: workout().exercises[0]!.sets[1]!.id, weight: { value: 62.5, unit: 'kg' }, reps: null, is_logged: false }]);
    });

    it('WO-003 a field left unchanged sends nothing; a value that is not a number goes back', async () => {
        const wrapper = row();

        await weight(wrapper).trigger('blur');
        await reps(wrapper).setValue('ten');
        await reps(wrapper).trigger('change');

        expect(wrapper.emitted('send')).toBeUndefined();
        expect(reps(wrapper).element.value).toBe('10');
    });

    it('WO-003 Log sends the weight and reps shown, as typed with a comma for the point', async () => {
        const wrapper = row();

        await weight(wrapper).setValue('85,5');
        await reps(wrapper).setValue('8');
        await wrapper.get('[data-test="log"]').trigger('click');

        const [change] = wrapper.emitted('send')!.at(-1) as [WorkoutWrite];
        expect(change.input).toEqual({ set_id: workout().exercises[0]!.sets[0]!.id, weight: { value: 85.5, unit: 'kg' }, reps: 8, is_logged: true });
        expect(change.haptic).toBe(true);
    });

    it('OFF-009 a set whose log waits to sync shows what the user typed, ticked, with a pending mark', () => {
        vi.mocked(pendingInput).mockReturnValue({ set_id: workout().exercises[0]!.sets[0]!.id, weight: { value: 85, unit: 'kg' }, reps: 7, is_logged: true } as never);
        const wrapper = row();

        expect(weight(wrapper).element.value).toBe('85');
        expect(reps(wrapper).element.value).toBe('7');
        expect(wrapper.get('[data-test="log"]').attributes('aria-checked')).toBe('true');
        expect(wrapper.get('[data-test="pending"]').text()).toContain('Waiting to sync');
        expect(pendingInput).toHaveBeenCalledWith('workout_set_log', expect.any(Function));
    });

    it('OFF-009 a set with nothing waiting shows no pending mark', () => {
        expect(row().find('[data-test="pending"]').exists()).toBe(false);
    });

    it('WO-007 the info icon explains the target and this week\'s RIR', () => {
        const wrapper = row();

        const info = wrapper.get('[data-test="rir-info"]');
        expect(info.attributes('aria-description')).toBe('Reps in reserve');
    });

    it('WO-025 a logged set shows as logged', () => {
        const wrapper = row((payload) => {
            payload.exercises[0]!.sets[0]!.is_logged = true;
        });

        expect(wrapper.get('[data-test="log"]').attributes('aria-checked')).toBe('true');
    });
});
