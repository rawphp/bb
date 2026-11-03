import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, readFresh, writeNow } from '@/app/call';
import type { ExerciseShowOutput } from '@/contracts/exercise_show';
import type { ExercisesShowOutput } from '@/contracts/exercises_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import ExerciseDetailContent from './ExerciseDetailContent.vue';
import ExerciseView from './ExerciseView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), writeNow: vi.fn() }));

const readMock = vi.mocked(read);
const readFreshMock = vi.mocked(readFresh);
const writeMock = vi.mocked(writeNow);
const bench = screenFixture<ExerciseShowOutput>('exercise_show');
const landmine = screenFixture<ExerciseShowOutput>('exercise_show.empty');
const library = screenFixture<ExercisesShowOutput>('exercises_show');

let wrapper: VueWrapper | undefined;

function show(props: { exerciseId?: string; payload?: ExerciseShowOutput; pending?: boolean; tab?: string } = {}): VueWrapper {
    wrapper = mount(ExerciseView, { props, attachTo: document.body });
    return wrapper;
}

function card(view: VueWrapper, name: string) {
    return view.get(`[data-card="${name}"]`);
}

function noteValue(view: VueWrapper): string {
    return (view.get('textarea[aria-label="Pinned note"]').element as HTMLTextAreaElement).value;
}

function stepValue(view: VueWrapper): string {
    return (view.get('input[aria-label="Load step in kg"]').element as HTMLInputElement).value;
}

async function openHistory(view: VueWrapper): Promise<void> {
    await view.findAll('[role="tab"]').find((t) => t.text() === 'History')!.trigger('click');
}

describe('ExerciseView', () => {
    beforeEach(() => {
        readMock.mockReset().mockImplementation(async (name) => (name === 'exercises_show' ? library : bench));
        writeMock.mockReset().mockResolvedValue({});
    });
    afterEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
        document.body.innerHTML = '';
    });

    it('EX-002 opens on the Detail tab with the video, muscle, equipment, load type and rep range, without calling the server', () => {
        const view = show({ payload: bench });

        expect(view.get('h1').text()).toBe('Barbell Bench Press');
        const tabs = view.findAll('[role="tab"]');
        expect(tabs.map((t) => [t.text(), t.attributes('aria-selected')])).toEqual([
            ['Detail', 'true'],
            ['History', 'false'],
        ]);
        const video = view.get('a[href="https://www.youtube.com/watch?v=example-bench"]');
        expect(video.text()).toContain('Watch on YouTube');
        expect(video.attributes('target')).toBe('_blank');
        expect(video.attributes('rel')).toContain('noopener');
        const facts = view.findAll('[data-fact]').map((f) => f.findAll('span').map((s) => s.text()));
        expect(facts).toEqual([
            ['Muscle', 'Chest'],
            ['Equipment', 'Barbell'],
            ['Load type', 'Barbell'],
            ['Rep range', '5–10 reps'],
        ]);
        expect(readMock).not.toHaveBeenCalled();
    });

    it('VIEW-001 the Detail tab is ExerciseDetailContent, given the exercise', () => {
        const view = show({ payload: bench, pending: true });
        const detail = view.getComponent(ExerciseDetailContent);

        expect(detail.props('exercise')).toEqual(bench);
        expect(detail.props('pending')).toBe(true);
    });

    it('EX-001 an exercise without a video has no video card, and one without a rep range has no rep range row', () => {
        const view = show({ payload: landmine });

        expect(view.text()).not.toContain('Watch on YouTube');
        expect(view.findAll('[data-fact]').map((f) => f.find('span').text())).toEqual(['Muscle', 'Equipment', 'Load type']);
    });

    it('EX-010 shows the pinned note; Save is off until it changes, then sends the trimmed note', async () => {
        const view = show({ payload: bench });
        const note = view.get('textarea[aria-label="Pinned note"]');
        const save = () => view.get('[data-note-save]');

        expect((note.element as HTMLTextAreaElement).value).toBe('Shoulder blades back and down, feet planted.');
        expect(view.text()).toContain('It shows every time this exercise appears.');
        expect(save().attributes('disabled')).toBeDefined();
        await note.setValue('  Elbows at 45°.  ');
        expect(save().attributes('disabled')).toBeUndefined();
        await save().trigger('click');
        await flushPromises();

        const [name, input] = writeMock.mock.calls[0]!;
        expect(name).toBe('exercise_note_set');
        expect(input).toEqual({ exercise_id: bench.id, note: 'Elbows at 45°.' });
        expect(inputViolations('exercise_note_set', input)).toEqual([]);
        expect(readMock).toHaveBeenCalledWith('exercise_show', { exercise_id: bench.id }, expect.any(Function), expect.any(Function));
    });

    it('EX-010 after a save the fields show the server\'s answer', async () => {
        readMock.mockReset().mockResolvedValue({ ...bench, pinned_note: 'Elbows at 45°.', load_step: { ...bench.load_step!, step: { value: 5, unit: 'kg', text: '5 kg' } } });
        const view = show({ payload: bench });

        await view.get('textarea[aria-label="Pinned note"]').setValue('  Elbows at 45°.  ');
        await view.get('[data-note-save]').trigger('click');
        await flushPromises();

        expect((view.get('textarea[aria-label="Pinned note"]').element as HTMLTextAreaElement).value).toBe('Elbows at 45°.');
        expect(view.get('[data-note-save]').attributes('disabled')).toBeDefined();
    });

    it('EX-010 EX-012 saving one field keeps what was typed but not saved in the other', async () => {
        readMock.mockReset().mockResolvedValue({ ...bench, load_step: { ...bench.load_step!, step: { value: 5, unit: 'kg', text: '5 kg' } } });
        const view = show({ payload: bench });

        await view.get('textarea[aria-label="Pinned note"]').setValue('Not saved yet');
        await view.get('input[aria-label="Load step in kg"]').setValue('5');
        await view.get('[data-step-save]').trigger('click');
        await flushPromises();

        expect((view.get('textarea[aria-label="Pinned note"]').element as HTMLTextAreaElement).value).toBe('Not saved yet');
        expect((view.get('input[aria-label="Load step in kg"]').element as HTMLInputElement).value).toBe('5');
    });

    it('EX-010 a fresh read fills a field the user has not touched', async () => {
        readMock.mockReset().mockResolvedValue({ ...bench, pinned_note: 'From another device.' });
        const view = show({ payload: bench });

        await view.get('input[aria-label="Load step in kg"]').setValue('5');
        await view.get('[data-step-save]').trigger('click');
        await flushPromises();

        expect((view.get('textarea[aria-label="Pinned note"]').element as HTMLTextAreaElement).value).toBe('From another device.');
    });

    it('EX-010 a blank note cannot be saved; Clear removes the note', async () => {
        const view = show({ payload: bench });

        await view.get('textarea[aria-label="Pinned note"]').setValue('   ');
        expect(view.get('[data-note-save]').attributes('disabled')).toBeDefined();
        await view.get('[data-note-clear]').trigger('click');
        await flushPromises();

        const input = writeMock.mock.calls[0]![1];
        expect(writeMock.mock.calls[0]![0]).toBe('exercise_note_set');
        expect(input).toEqual({ exercise_id: bench.id, note: null });
        expect(inputViolations('exercise_note_set', input)).toEqual([]);
    });

    it('EX-010 Clear is off when there is no note', () => {
        const view = show({ payload: landmine });

        expect((view.get('textarea[aria-label="Pinned note"]').element as HTMLTextAreaElement).value).toBe('');
        expect(view.get('[data-note-clear]').attributes('disabled')).toBeDefined();
    });

    it('EX-012 shows the exercise\'s load step with the default as the placeholder, and saves a typed one in the server\'s unit', async () => {
        const view = show({ payload: bench });
        const step = view.get('input[aria-label="Load step in kg"]');

        expect((step.element as HTMLInputElement).value).toBe('1.25');
        expect(step.attributes('placeholder')).toBe('2.5 kg');
        expect(view.text()).toContain('Leave it empty to use the default, 2.5 kg.');
        await step.setValue('4,5');
        await view.get('[data-step-save]').trigger('click');
        await flushPromises();

        const [name, input] = writeMock.mock.calls[0]!;
        expect(name).toBe('exercise_load_step_set');
        expect(input).toEqual({ exercise_id: bench.id, step: 4.5, unit: 'kg' });
        expect(inputViolations('exercise_load_step_set', input)).toEqual([]);
    });

    it('EX-012 an empty load step goes back to the default; one that is not a number cannot be saved', async () => {
        const view = show({ payload: bench });
        const step = view.get('input[aria-label="Load step in kg"]');

        await step.setValue('lots');
        expect(view.get('[data-step-save]').attributes('disabled')).toBeDefined();
        await step.setValue('');
        await view.get('[data-step-save]').trigger('click');
        await flushPromises();

        const input = writeMock.mock.calls[0]![1];
        expect(input).toEqual({ exercise_id: bench.id, step: null, unit: 'kg' });
        expect(inputViolations('exercise_load_step_set', input)).toEqual([]);
    });

    it('EX-012 an empty load step shows the default with its unit once, and a typed one beside the unit', async () => {
        const view = show({ payload: landmine });
        const field = view.get('input[aria-label="Load step in kg"]');

        expect(field.attributes('placeholder')).toBe('2.5 kg');
        expect(field.element.parentElement!.textContent!.trim()).toBe('');
        await field.setValue('5');
        expect(field.element.parentElement!.textContent!.trim()).toBe('kg');
    });

    it('EX-005 an exercise with no load to step says it is reps only', () => {
        const view = show({ payload: { ...landmine, load_type: 'bodyweight', load_type_text: 'Bodyweight', load_step: null } });

        expect(view.find('input[aria-label^="Load step"]').exists()).toBe(false);
        expect(view.text()).toContain('Reps only. Bodyweight exercises have no load to step.');
    });

    it('CAP-012 a refused write shows the server\'s words', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('exercise_load_step_set', 422, { error: { message: 'The step must be at least 0.25.' } }));
        const view = show({ payload: bench });

        await view.get('input[aria-label="Load step in kg"]').setValue('0.1');
        await view.get('[data-step-save]').trigger('click');
        await flushPromises();

        expect(view.get('[role="alert"]').text()).toContain('The step must be at least 0.25.');
        expect(stepValue(view)).toBe('0.1');
    });

    it('CAP-014 a note save with no final answer keeps the typed note while the screen reads the server again', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('exercise_note_set', 502, null));
        readFreshMock.mockResolvedValue({ ...bench, pinned_note: 'Changed elsewhere.' });
        const view = show({ payload: bench });

        await view.get('textarea[aria-label="Pinned note"]').setValue('Typed here');
        await view.get('[data-note-save]').trigger('click');
        await flushPromises();

        expect(readFreshMock).toHaveBeenCalledWith('exercise_show', { exercise_id: bench.id });
        expect(view.get('[role="alert"]').text()).toContain('may have worked');
        expect(noteValue(view)).toBe('Typed here');
    });

    it('EX-012 after a load step save the field shows the server\'s step', async () => {
        readMock.mockReset().mockResolvedValue({ ...bench, load_step: { ...bench.load_step!, step: { value: 4.5, unit: 'kg', text: '4.5 kg' } } });
        const view = show({ payload: bench });

        await view.get('input[aria-label="Load step in kg"]').setValue('4,5');
        await view.get('[data-step-save]').trigger('click');
        await flushPromises();

        expect(stepValue(view)).toBe('4.5');
    });

    it('EX-010 EX-012 every read fills the fields the user has not touched, not just the first', async () => {
        const withStep = (value: number) => ({ value, unit: 'kg', text: `${value} kg` });
        readMock
            .mockReset()
            .mockResolvedValueOnce({ ...bench, pinned_note: 'Second', load_step: { ...bench.load_step!, step: withStep(5) } })
            .mockResolvedValueOnce({ ...bench, pinned_note: 'Third', load_step: { ...bench.load_step!, step: withStep(7.5) } });
        const view = show({ payload: bench });

        await view.get('input[aria-label="Load step in kg"]').setValue('5');
        await view.get('[data-step-save]').trigger('click');
        await flushPromises();
        expect(noteValue(view)).toBe('Second');
        await view.get('textarea[aria-label="Pinned note"]').setValue('Third');
        await view.get('[data-note-save]').trigger('click');
        await flushPromises();

        expect(noteValue(view)).toBe('Third');
        expect(stepValue(view)).toBe('7.5');
    });

    it('EX-010 EX-012 what was typed on Detail stays after a look at History', async () => {
        const view = show({ payload: bench });

        await view.get('textarea[aria-label="Pinned note"]').setValue('Typed, not saved');
        await view.get('input[aria-label="Load step in kg"]').setValue('3');
        await openHistory(view);
        await view.findAll('[role="tab"]').find((t) => t.text() === 'Detail')!.trigger('click');

        expect(noteValue(view)).toBe('Typed, not saved');
        expect(stepValue(view)).toBe('3');
    });

    it('EX-002 the History tab lists every session by date with its sets and the best e1RM marked', async () => {
        const view = show({ payload: bench });

        await openHistory(view);
        const sessions = view.findAll('[data-session]');
        expect(sessions).toHaveLength(2);
        expect(sessions[0]!.text()).toContain('Thu, 8 Oct 2026');
        expect(sessions[0]!.findAll('[data-set]').map((s) => s.get('span').text())).toEqual(['82.5 kg × 9', '82.5 kg × 8', '80.0 kg × 10']);
        expect(sessions[0]!.findAll('[data-set]').map((s) => s.text().includes('Best e1RM'))).toEqual([true, false, false]);
        expect(sessions[0]!.text()).toContain('Best e1RM 107.2 kg');
        expect(view.get('textarea').isVisible()).toBe(false);
    });

    it('EX-002 the History tab says when no session is logged yet', async () => {
        const view = show({ payload: landmine });

        await openHistory(view);
        expect(view.findAll('[data-session]')).toHaveLength(0);
        expect(view.text()).toContain('No sessions logged yet.');
    });

    it('EX-002 can open on the History tab', () => {
        const view = show({ payload: bench, tab: 'history' });

        expect(view.findAll('[data-session]')).toHaveLength(2);
    });

    it('CAP-004 shows a skeleton while it reads exercise_show for the exercise in the route', async () => {
        const view = show({ exerciseId: bench.id });

        expect(view.find('[data-test="skeleton"]').exists()).toBe(true);
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('exercise_show', { exercise_id: bench.id }, expect.any(Function), expect.any(Function));
        expect(view.get('h1').text()).toBe('Barbell Bench Press');
        expect((view.get('textarea[aria-label="Pinned note"]').element as HTMLTextAreaElement).value).toBe(bench.pinned_note);
        expect((view.get('input[aria-label="Load step in kg"]').element as HTMLInputElement).value).toBe('1.25');
    });

    it('CAP-004 says why the exercise did not load, and Try again reads it again', async () => {
        readMock.mockReset().mockRejectedValueOnce(new CapabilityError('exercise_show', 500, null)).mockResolvedValueOnce(bench);
        const view = show({ exerciseId: bench.id });
        await flushPromises();

        expect(view.get('[role="alert"]').text()).toContain('This exercise could not be loaded.');
        await view.get('[data-test="retry"]').trigger('click');
        await flushPromises();
        expect(view.get('h1').text()).toBe('Barbell Bench Press');
    });

    it('OFF-008 marks the server\'s details and history as waiting to sync', async () => {
        const view = show({ payload: bench, pending: true });

        expect(card(view, 'details').text()).toContain('Waiting to sync');
        await openHistory(view);
        expect(view.findAll('[data-session]').every((s) => s.text().includes('Waiting to sync'))).toBe(true);
    });

    it('OFF-008 marks nothing when nothing waits to sync', () => {
        expect(show({ payload: bench }).text()).not.toContain('Waiting to sync');
    });

    it('EX-004 only a custom exercise has Edit', () => {
        expect(show({ payload: bench }).find('button[aria-label="Edit exercise"]').exists()).toBe(false);
        wrapper!.unmount();
        expect(show({ payload: landmine }).find('button[aria-label="Edit exercise"]').exists()).toBe(true);
    });

    it('EX-004 Edit opens the custom exercise with the library\'s choices, and a save reads the exercise again', async () => {
        const view = show({ payload: landmine });

        await view.get('button[aria-label="Edit exercise"]').trigger('click');
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('exercises_show', {});
        expect(document.body.querySelector('[role="dialog"] h2')!.textContent).toBe('Edit exercise');
        expect(document.body.querySelector<HTMLInputElement>('[name="name"]')!.value).toBe('Landmine Press (my gym)');
        document.body.querySelector<HTMLButtonElement>('[data-save]')!.click();
        await flushPromises();

        const input = writeMock.mock.calls[0]![1];
        expect(writeMock.mock.calls[0]![0]).toBe('custom_exercise_save');
        expect(input).toEqual({ exercise_id: landmine.id, name: 'Landmine Press (my gym)', muscle_group: 'frontDelts', equipment: 'barbell', load_type: 'barbell' });
        expect(inputViolations('custom_exercise_save', input)).toEqual([]);
        expect(readMock).toHaveBeenCalledWith('exercise_show', { exercise_id: landmine.id }, expect.any(Function), expect.any(Function));
        expect(document.body.querySelector('[role="dialog"] h2')).toBeNull();
    });

    it('CAP-012 Edit says so when the choices could not be loaded', async () => {
        readMock.mockReset().mockRejectedValueOnce(new CapabilityError('exercises_show', 0, null));
        const view = show({ payload: landmine });

        await view.get('button[aria-label="Edit exercise"]').trigger('click');
        await flushPromises();

        expect(view.get('[role="alert"]').text()).toContain('No connection.');
        expect(document.body.querySelector('[role="dialog"] h2')).toBeNull();
    });
});
