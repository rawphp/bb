import { flushPromises, mount, RouterLinkStub, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, writeNow } from '@/app/call';
import type { ExercisesShowOutput } from '@/contracts/exercises_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import ExerciseLibraryView from './ExerciseLibraryView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), writeNow: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(writeNow);
const content = screenFixture<ExercisesShowOutput>('exercises_show');
const empty = screenFixture<ExercisesShowOutput>('exercises_show.empty');

let wrapper: VueWrapper | undefined;

function show(props: { payload?: ExercisesShowOutput; pending?: boolean } = {}): VueWrapper {
    wrapper = mount(ExerciseLibraryView, { props, attachTo: document.body, global: { stubs: { RouterLink: RouterLinkStub } } });
    return wrapper;
}

function names(view: VueWrapper): string[] {
    return view.findAll('[data-exercise] [data-name]').map((el) => el.text());
}

describe('ExerciseLibraryView', () => {
    beforeEach(() => {
        readMock.mockReset().mockResolvedValue(content);
        writeMock.mockReset().mockResolvedValue({});
    });
    afterEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
        document.body.innerHTML = '';
    });

    it('EX-001 EX-013 lists every exercise with its muscle and equipment, the date it was last used, and a mark when it has a video', () => {
        const view = show({ payload: content });

        expect(view.get('h1').text()).toBe('Exercises');
        const rows = view.findAll('[data-exercise]');
        expect(rows).toHaveLength(10);
        expect(rows[0]!.text()).toContain('45-Degree Hip Extension');
        expect(rows[0]!.text()).toContain('Hamstrings · Machine');
        expect(rows[0]!.text()).toContain('Last used 9 Oct 2026');
        expect(rows[3]!.text()).toContain('Never used');
        expect(rows[0]!.find('[aria-label="Has a technique video"]').exists()).toBe(true);
        expect(rows[6]!.find('[aria-label="Has a technique video"]').exists()).toBe(false);
        expect(readMock).not.toHaveBeenCalled();
    });

    it('EX-002 each row opens that exercise', () => {
        const view = show({ payload: content });

        expect(view.findAllComponents(RouterLinkStub)[7]!.props('to')).toBe('/exercises/01JA8Q0V3K2M4N6P8R0T2W4Y6H');
    });

    it('EX-003 search keeps the exercises whose name holds the text, in any case', async () => {
        const view = show({ payload: content });

        await view.get('input[aria-label="Search exercises"]').setValue('  DUMBBELL ');

        expect(names(view)).toEqual(['Alternating Dumbbell Curl', 'Alternating Dumbbell Front Raise']);
    });

    it('EX-003 the muscle and equipment filters offer the server\'s groups and narrow the list together', async () => {
        const view = show({ payload: content });
        const muscle = view.get('select[aria-label="Muscle filter"]');
        const equipment = view.get('select[aria-label="Equipment filter"]');

        expect(muscle.findAll('option').map((o) => o.text())).toEqual(['Muscle: All', ...content.muscle_groups.map((g) => g.label)]);
        expect(equipment.findAll('option').map((o) => o.text())).toEqual(['Equipment: All', ...content.equipment.map((g) => g.label)]);
        await muscle.setValue('frontDelts');
        expect(names(view)).toEqual(['Alternating Dumbbell Front Raise', 'Arnold Press', 'Landmine Press (my gym)']);
        await equipment.setValue('barbell');
        expect(names(view)).toEqual(['Landmine Press (my gym)']);
        await muscle.setValue('');
        expect(names(view)).toEqual(['Barbell Bench Press', 'Landmine Press (my gym)']);
    });

    it('EX-003 says no exercises match when the search and filters leave none', async () => {
        const view = show({ payload: content });

        await view.get('input[aria-label="Search exercises"]').setValue('zercher');

        expect(view.findAll('[data-exercise]')).toHaveLength(0);
        expect(view.text()).toContain('No exercises match.');
    });

    it('EX-003 says no exercises match when a filter alone leaves none', async () => {
        const view = show({ payload: content });

        await view.get('select[aria-label="Muscle filter"]').setValue('back');

        expect(view.findAll('[data-exercise]')).toHaveLength(0);
        expect(view.text()).toContain('No exercises match.');
    });

    it('EX-001 says there are no exercises yet when the library is empty', () => {
        const view = show({ payload: empty });

        expect(view.findAll('[data-exercise]')).toHaveLength(0);
        expect(view.text()).toContain('No exercises yet.');
    });

    it('CAP-004 shows a skeleton while it reads exercises_show, then the list', async () => {
        const view = show();

        expect(view.find('[data-test="skeleton"]').exists()).toBe(true);
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('exercises_show', {}, expect.any(Function), expect.any(Function));
        expect(view.findAll('[data-exercise]')).toHaveLength(10);
    });

    it('CAP-004 says why the library did not load, and Try again reads it again', async () => {
        readMock.mockReset().mockRejectedValueOnce(new CapabilityError('exercises_show', 500, null)).mockResolvedValueOnce(content);
        const view = show();
        await flushPromises();

        expect(view.get('[role="alert"]').text()).toContain('The exercise library could not be loaded.');
        await view.get('[data-test="retry"]').trigger('click');
        await flushPromises();
        expect(view.findAll('[data-exercise]')).toHaveLength(10);
    });

    it('OFF-008 marks each row\'s last-used date as waiting to sync', () => {
        const view = show({ payload: content, pending: true });

        expect(view.findAll('[data-exercise]').every((row) => row.text().includes('Waiting to sync'))).toBe(true);
    });

    it('OFF-008 marks nothing when nothing waits to sync', () => {
        expect(show({ payload: content }).text()).not.toContain('Waiting to sync');
    });

    it('EX-004 + opens a new exercise with the library\'s choices, and a save reads the library again', async () => {
        const view = show({ payload: content });

        await view.get('button[aria-label="New exercise"]').trigger('click');
        expect(document.body.querySelector('[role="dialog"] h2')!.textContent).toBe('New exercise');
        const set = (name: string, value: string) => {
            const el = document.body.querySelector<HTMLInputElement | HTMLSelectElement>(`[name="${name}"]`)!;
            el.value = value;
            el.dispatchEvent(new Event(el instanceof HTMLSelectElement ? 'change' : 'input'));
        };
        set('name', 'Seal row');
        set('muscle_group', 'back');
        set('equipment', 'dumbbell');
        set('load_type', 'dumbbell');
        await flushPromises();
        document.body.querySelector<HTMLButtonElement>('[data-save]')!.click();
        await flushPromises();

        expect(writeMock).toHaveBeenCalledWith('custom_exercise_save', expect.objectContaining({ name: 'Seal row' }), expect.anything());
        expect(inputViolations('custom_exercise_save', writeMock.mock.calls[0]![1])).toEqual([]);
        expect(readMock).toHaveBeenCalledWith('exercises_show', {}, expect.any(Function), expect.any(Function));
        expect(document.body.querySelector('[role="dialog"] h2')).toBeNull();
    });
});
