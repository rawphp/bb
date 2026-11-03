import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { pendingInput } from '@/app/outbox';
import type { WorkoutFeedbackAnswerInput } from '@/contracts/workout_feedback_answer';
import type { WorkoutShowOutput } from '@/contracts/workout_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import type { WorkoutWrite } from '../workoutWrite';
import FeedbackSheet from './FeedbackSheet.vue';

vi.mock('@/app/outbox', async (original) => ({ ...(await original<typeof import('@/app/outbox')>()), pendingInput: vi.fn(() => undefined) }));

const payload = () => screenFixture<WorkoutShowOutput>('workout_show.warning');
const WORKOUT = '01JB8W3Q000000000000000901';
const BENCH_LIBRARY = '01JB8W3Q000000000000000101';

function show(data = payload(), muscle = 'chest') {
    return mount(FeedbackSheet, { props: { payload: data, muscle }, attachTo: document.body, global: { stubs: { teleport: true, RouterLink: true } } });
}

type Wrapper = ReturnType<typeof show>;

const group = (wrapper: Wrapper, name: string) => wrapper.get(`[role="radiogroup"][aria-label="${name}"]`);
const choose = (wrapper: Wrapper, name: string, label: string) => group(wrapper, name).get(`[role="radio"][aria-label="${label}"]`).trigger('click');
const JOINT = 'How did your joints feel during Bench Press?';
const SORENESS = 'How sore did you get in your chest AFTER training them LAST TIME?';
const questions = (wrapper: Wrapper) => wrapper.findAll('[role="radiogroup"]').map((list) => list.attributes('aria-label'));

async function answerAll(wrapper: Wrapper): Promise<void> {
    await choose(wrapper, JOINT, 'Low pain');
    for (const radiogroup of wrapper.findAll('[role="radiogroup"]').slice(1)) await radiogroup.get('[role="radio"]').trigger('click');
}

function saved(wrapper: Wrapper): WorkoutFeedbackAnswerInput {
    const [change] = wrapper.emitted('send')![0] as [WorkoutWrite];
    expect(change.name).toBe('workout_feedback_answer');
    expect(inputViolations(change.name, change.input)).toEqual([]);
    return change.input as unknown as WorkoutFeedbackAnswerInput;
}

describe('FeedbackSheet', () => {
    beforeEach(() => {
        vi.mocked(pendingInput).mockReturnValue(undefined);
        document.body.innerHTML = '';
    });

    it('FB-001 shows the muscle\'s title and its questions in order: joint pain per exercise, soreness, pump, volume', () => {
        const wrapper = show();

        expect(wrapper.get('[role="dialog"] h2').text()).toBe('Chest');
        expect(questions(wrapper)).toEqual([JOINT, SORENESS, 'How much of a pump did you get today in your chest?', 'How was the volume of work (number of hard sets) for your chest?']);
        expect(wrapper.findAll('h3').map((heading) => heading.text())).toEqual(questions(wrapper));
    });

    it('FB-002 offers the server\'s answers for each question', () => {
        const wrapper = show();

        expect(group(wrapper, JOINT).findAll('[role="radio"]').map((radio) => radio.attributes('aria-label'))).toEqual(['None', 'Low pain', 'Moderate pain', 'A lot of pain']);
        expect(group(wrapper, SORENESS).findAll('[role="radio"]')).toHaveLength(4);
    });

    it('FB-006 every question has a ? icon that explains it', () => {
        const wrapper = show();

        const helps = wrapper.findAll('button[aria-label="Help"]');
        expect(helps).toHaveLength(4);
        expect(helps[0]!.attributes('aria-description')).toBe('Joint pain');
    });

    it('FB-007 asks no soreness when the server sent no soreness question', () => {
        const data = payload();
        data.feedback[0]!.questions = data.feedback[0]!.questions.filter((question) => question.kind !== 'soreness');
        const wrapper = show(data);

        expect(questions(wrapper)).toHaveLength(3);
        expect(questions(wrapper)).not.toContain(SORENESS);
    });

    it('UI-022 a tap outside the sheet and Escape do not close it; only Cancel does', async () => {
        const wrapper = show();

        await wrapper.get('[data-scrim]').trigger('click');
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('close')).toBeUndefined();
        await wrapper.get('[data-cancel]').trigger('click');
        expect(wrapper.emitted('close')).toHaveLength(1);
        expect(wrapper.emitted('send')).toBeUndefined();
    });

    it('FB-001 Save stays off until every question has an answer', async () => {
        const wrapper = show();
        expect(wrapper.get('[data-save]').attributes('disabled')).toBeDefined();

        await choose(wrapper, JOINT, 'None');
        await choose(wrapper, SORENESS, 'Never got sore');
        await choose(wrapper, 'How much of a pump did you get today in your chest?', 'Low pump');
        expect(wrapper.get('[data-save]').attributes('disabled')).toBeDefined();

        await choose(wrapper, 'How was the volume of work (number of hard sets) for your chest?', 'Just right');

        expect(wrapper.get('[data-save]').attributes('disabled')).toBeUndefined();
    });

    it('FB-002 a joint answer with a note shows it in a warning box below the joint questions', async () => {
        const wrapper = show();
        expect(wrapper.find('[data-test="safety-note"]').exists()).toBe(false);

        await choose(wrapper, JOINT, 'A lot of pain');

        const note = wrapper.get('[data-test="safety-note"]');
        expect(note.text()).toContain('Stop this exercise if the pain is sharp, and see a professional if it persists.');
        expect(note.classes().join(' ')).toContain('tone-warning');
        await choose(wrapper, JOINT, 'None');
        expect(wrapper.find('[data-test="safety-note"]').exists()).toBe(false);
    });

    it('FB-001 Save sends workout_feedback_answer with every answer, and closes', async () => {
        const wrapper = show();

        await choose(wrapper, JOINT, 'Moderate pain');
        await choose(wrapper, SORENESS, "I'm still sore!");
        await choose(wrapper, 'How much of a pump did you get today in your chest?', 'Amazing pump');
        await choose(wrapper, 'How was the volume of work (number of hard sets) for your chest?', 'Pushed my limits');
        await wrapper.get('[data-save]').trigger('click');

        expect(saved(wrapper)).toEqual({
            workout_id: WORKOUT,
            muscle_group: 'chest',
            joint_pain: [{ exercise_id: BENCH_LIBRARY, answer: 'moderate' }],
            soreness: 'stillSore',
            pump: 'amazing',
            volume: 'pushedMyLimits',
        });
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('FB-007 with no soreness question the answer leaves soreness out', async () => {
        const data = payload();
        data.feedback[0]!.questions = data.feedback[0]!.questions.filter((question) => question.kind !== 'soreness');
        const wrapper = show(data);

        await answerAll(wrapper);
        await wrapper.get('[data-save]').trigger('click');

        expect(saved(wrapper)).not.toHaveProperty('soreness');
    });

    it('FB-009 answers already given show, and the sheet opens complete', () => {
        const data = payload();
        const entry = data.feedback[0]!;
        entry.is_answered = true;
        entry.questions.forEach((question) => (question.answer = question.choices[1]!.value));
        const wrapper = show(data);

        expect(group(wrapper, JOINT).get('[aria-checked="true"]').attributes('aria-label')).toBe('Low pain');
        expect(wrapper.get('[data-save]').attributes('disabled')).toBeUndefined();
    });

    it('OFF-009 a waiting answer for this workout and muscle seeds the sheet, so a reopened one shows it', () => {
        vi.mocked(pendingInput).mockReturnValue({
            workout_id: WORKOUT,
            muscle_group: 'chest',
            joint_pain: [{ exercise_id: BENCH_LIBRARY, answer: 'aLot' }],
            soreness: 'neverGotSore',
            pump: 'low',
            volume: 'tooMuch',
        } satisfies WorkoutFeedbackAnswerInput);
        const wrapper = show();

        expect(group(wrapper, JOINT).get('[aria-checked="true"]').attributes('aria-label')).toBe('A lot of pain');
        expect(group(wrapper, 'How was the volume of work (number of hard sets) for your chest?').get('[aria-checked="true"]').attributes('aria-label')).toBe('Too much');
        expect(wrapper.get('[data-save]').attributes('disabled')).toBeUndefined();
        const lookup = vi.mocked(pendingInput).mock.calls[0]!;
        expect(lookup[0]).toBe('workout_feedback_answer');
        expect(lookup[1]({ workout_id: WORKOUT, muscle_group: 'chest' } as WorkoutFeedbackAnswerInput)).toBe(true);
        expect(lookup[1]({ workout_id: WORKOUT, muscle_group: 'back' } as WorkoutFeedbackAnswerInput)).toBe(false);
    });

    it('FB-009 a session that can no longer change shows the answers but takes none: no Save', () => {
        const data = payload();
        data.feedback[0]!.can_edit = false;
        const wrapper = show(data);

        expect(wrapper.find('[data-save]').exists()).toBe(false);
        expect(group(wrapper, JOINT).get('[role="radio"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-cancel]').text()).toBe('Done');
    });

    it('FB-009 a finished workout is read only too', () => {
        const wrapper = show({ ...payload(), is_editable: false });

        expect(wrapper.find('[data-save]').exists()).toBe(false);
    });
});
