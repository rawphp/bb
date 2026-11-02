import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { beforeEach, describe, expect, it, onTestFinished, vi } from 'vitest';
import { CapabilityError, read, readFresh, write } from '@/app/call';
import type { MesocycleShowOutput } from '@/contracts/mesocycle_show';
import type { TemplatesShowOutput } from '@/contracts/templates_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import PriorityRowView from '../PriorityRowView.vue';
import MesocycleBuildView from './MesocycleBuildView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), readFresh: vi.fn(), write: vi.fn() }));
const router = { replace: vi.fn(), push: vi.fn(), back: vi.fn() };
vi.mock('vue-router', async (original) => ({ ...(await original<typeof import('vue-router')>()), useRouter: () => router }));

const readMock = vi.mocked(read);
const readFreshMock = vi.mocked(readFresh);
const writeMock = vi.mocked(write);
const empty = screenFixture<MesocycleShowOutput>('mesocycle_show.empty');
const warning = screenFixture<MesocycleShowOutput>('mesocycle_show.warning');
const saved = screenFixture<MesocycleShowOutput>('mesocycle_show');
const templates = screenFixture<TemplatesShowOutput>('templates_show');
const ID = empty.id;
const ULID = /^[0-9A-HJKMNP-TV-Z]{26}$/;

// What the next mesocycle_show read answers.
let draft: MesocycleShowOutput;

function show(props: Record<string, unknown> = { payload: empty }) {
    return mount(MesocycleBuildView, { props, global: { stubs: { RouterLink: RouterLinkStub, teleport: true } } });
}

// The capability and input of the write sent last / first.
function lastWrite(): [string, Record<string, unknown>] {
    return writeMock.mock.calls.at(-1)!.slice(0, 2) as [string, Record<string, unknown>];
}

function sent(): string[] {
    return writeMock.mock.calls.map((call) => call[0]);
}

async function choose(wrapper: ReturnType<typeof show>, label: string, text: string): Promise<void> {
    const select = wrapper.find(`select[aria-label="${label}"]`);
    const element = select.element as HTMLSelectElement;
    element.selectedIndex = [...element.options].findIndex((o) => o.text === text);
    await select.trigger('change');
    await flushPromises();
}

async function click(wrapper: ReturnType<typeof show>, selector: string): Promise<void> {
    await wrapper.find(selector).trigger('click');
    await flushPromises();
}

const next = '[data-step="next"]';
const back = '[data-step="back"]';

function slotsOf(day: number) {
    return warning.build!.days[day]!.slots.map((s) => ({ slot_id: s.id, muscle: s.muscle, exercise_id: s.exercise_id ?? null, sets: s.sets }));
}

function allDays() {
    return warning.build!.days.map((d, index) => ({ day_id: d.id, slots: slotsOf(index) }));
}

describe('MesocycleBuildView', () => {
    beforeEach(() => {
        draft = warning;
        readMock.mockReset().mockImplementation(async (name) => (name === 'templates_show' ? templates : draft)) as never;
        readFreshMock.mockReset().mockImplementation(async () => draft) as never;
        writeMock.mockReset().mockResolvedValue({});
        router.replace.mockReset();
        router.push.mockReset();
        router.back.mockReset();
    });

    describe('Meso Builder step', () => {
        it('MESO-020 asks for days per week and a priority for each of the fourteen muscles, inside the step flow', () => {
            const wrapper = show();

            expect(wrapper.find('h1').text()).toBe('New mesocycle');
            expect(wrapper.text()).toContain('Step 1 of 3');
            expect(wrapper.find('h2').text()).toBe('Meso Builder');
            const days = wrapper.find('select[aria-label="Days per week"]');
            expect(days.findAll('option').map((o) => o.text())).toEqual(['2 days', '3 days', '4 days', '5 days', '6 days']);
            expect((days.element as HTMLSelectElement).selectedOptions[0]!.text).toBe('4 days');
            expect(wrapper.findAllComponents(PriorityRowView)).toHaveLength(14);
            expect(wrapper.text()).toContain('Muscle priorities');
            expect(wrapper.text()).toContain('Starting from the Muscle gain defaults.');
            expect(wrapper.find(back).attributes('disabled')).toBeDefined();
            expect(readMock).not.toHaveBeenCalled();
            expect(writeMock).not.toHaveBeenCalled();
        });

        it('MESO-020 sends days per week as a valid mesocycle_build, then reads the draft again', async () => {
            const wrapper = show();

            await choose(wrapper, 'Days per week', '5 days');

            expect(lastWrite()).toEqual(['mesocycle_build', { mesocycle_id: ID, days_per_week: 5 }]);
            expect(inputViolations(...lastWrite())).toEqual([]);
            expect(readMock).toHaveBeenCalledWith('mesocycle_show', { mesocycle_id: ID }, expect.any(Function), expect.any(Function));
        });

        it('MESO-020 sends a muscle\'s priority as a valid priority_set', async () => {
            const wrapper = show();
            const select = wrapper.find('select[aria-label="Chest priority"]');
            (select.element as HTMLSelectElement).selectedIndex = 2;
            await select.trigger('change');
            await flushPromises();

            expect(lastWrite()).toEqual(['priority_set', { mesocycle_id: ID, muscle: 'chest', priority: 'maintain' }]);
            expect(inputViolations(...lastWrite())).toEqual([]);
        });

        it('MESO-021 shows the warnings above the muscle rows, and Next still works', async () => {
            const warned = structuredClone(empty);
            warned.build!.warnings = warning.build!.warnings;
            warned.build!.priority_notice = { text: 'Fat loss trains every muscle twice.', tone: 'info' };
            const wrapper = show({ payload: warned });

            const warnings = wrapper.find('[data-test="warnings"]');
            expect(warnings.text()).toContain('Warnings');
            expect(warnings.text()).toContain('Emphasizing more than 2 muscles at once spreads your recovery thin');
            const firstRow = wrapper.findComponent(PriorityRowView).element;
            expect(warnings.element.compareDocumentPosition(firstRow) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
            expect(wrapper.text()).toContain('Fat loss trains every muscle twice.');

            await click(wrapper, next);
            expect(sent()).toEqual(['mesocycle_structure_generate']);
        });

        it('MESO-021 shows no Warnings heading when there are none', () => {
            expect(show().find('[data-test="warnings"]').exists()).toBe(false);
        });

        it('MESO-022 Continue sends a valid mesocycle_structure_generate and then shows the Exercises step', async () => {
            const wrapper = show();

            await click(wrapper, next);

            expect(lastWrite()).toEqual(['mesocycle_structure_generate', { mesocycle_id: ID }]);
            expect(inputViolations(...lastWrite())).toEqual([]);
            expect(wrapper.find('h2').text()).toBe('Exercises');
            expect(wrapper.text()).toContain('Step 2 of 3');
        });

        it('MESO-022 stays on the step and says why when the structure could not be built', async () => {
            writeMock.mockRejectedValueOnce(new CapabilityError('mesocycle_structure_generate', 422, { error: { message: 'Pick a number of days first.' } }));
            const wrapper = show();

            await click(wrapper, next);

            expect(wrapper.find('h2').text()).toBe('Meso Builder');
            expect(wrapper.find('[role="alert"]').text()).toBe('Pick a number of days first.');
        });
    });

    describe('Build from scratch step', () => {
        const scratch = () => show({ payload: empty, step: 'scratch' });

        it('MESO-005 asks for sessions per week and the day labels, then builds the sessions', async () => {
            const wrapper = scratch();

            expect(wrapper.find('h2').text()).toBe('Build from scratch');
            expect(wrapper.text()).toContain('Step 1 of 3');
            expect(wrapper.find('[role="radiogroup"]').attributes('aria-label')).toBe('Day labels');

            await choose(wrapper, 'Sessions per week', '3 days');
            expect(lastWrite()).toEqual(['mesocycle_build', { mesocycle_id: ID, days_per_week: 3 }]);
            expect(inputViolations(...lastWrite())).toEqual([]);

            await wrapper.find('[role="radio"][aria-label="Day 1, Day 2"]').trigger('click');
            await flushPromises();
            expect(lastWrite()).toEqual(['mesocycle_build', { mesocycle_id: ID, label_style: 'floating' }]);
            expect(inputViolations(...lastWrite())).toEqual([]);

            await click(wrapper, next);
            expect(lastWrite()).toEqual(['mesocycle_structure_generate', { mesocycle_id: ID }]);
            expect(wrapper.find('h2').text()).toBe('Exercises');
        });

        it('MESO-005 turns weekday names off, and says why, when no training days are set', () => {
            const floating = structuredClone(empty);
            floating.build!.weekdays_available = false;
            floating.build!.label_style = 'floating';
            const wrapper = show({ payload: floating, step: 'scratch' });

            const weekdays = wrapper.find('[role="radio"][aria-label="Weekdays"]');
            expect(weekdays.attributes('disabled')).toBeDefined();
            expect(wrapper.text()).toContain('Set your training days to use weekday names.');
            expect(wrapper.find('[role="radio"][aria-label="Day 1, Day 2"]').attributes('aria-checked')).toBe('true');
        });
    });

    describe('Exercises step', () => {
        const slots = () => show({ payload: warning });

        it('MESO-013 shows each day with a sets picker, an exercise picker and Delete for every slot', () => {
            const wrapper = slots();

            expect(wrapper.find('h2').text()).toBe('Exercises');
            expect(wrapper.text()).toContain('Step 2 of 3');
            const days = wrapper.findAll('[data-test="day"]');
            expect(days.map((d) => d.attributes('aria-label'))).toEqual(warning.build!.days.map((d) => d.label));
            const rows = days[0]!.findAll('[data-test="slot"]');
            expect(rows).toHaveLength(6);
            expect(rows[0]!.text()).toContain('Chest');
            expect(rows[0]!.text()).toContain('Assisted Chest Dip');
            expect(rows[0]!.text()).toContain('2 sets');
            expect(rows[0]!.find('select[aria-label="Chest sets"]').exists()).toBe(true);
            expect(rows[0]!.find('select[aria-label="Chest exercise"]').findAll('option').map((o) => o.text())).toEqual(['None', 'Assisted Chest Dip', 'Barbell Bench Press', 'Cable Fly']);
            expect(rows[0]!.find('button[aria-label="Delete Chest"]').exists()).toBe(true);
            expect(days[0]!.find('[data-test="menu"]').exists()).toBe(true);
        });

        it('MESO-013 picking an exercise sends every day with only that slot changed', async () => {
            const wrapper = slots();

            await choose(wrapper, 'Chest exercise', 'Cable Fly');

            const expected = allDays();
            expected[0]!.slots[0]!.exercise_id = '01JA0EX0300000000000000000';
            expect(lastWrite()).toEqual(['mesocycle_build', { mesocycle_id: ID, days: expected }]);
            expect(inputViolations(...lastWrite())).toEqual([]);
        });

        it('MESO-013 picking None clears the slot\'s exercise with a null exercise_id', async () => {
            const wrapper = slots();

            await choose(wrapper, 'Chest exercise', 'None');

            const expected = allDays();
            expected[0]!.slots[0]!.exercise_id = null;
            expect(lastWrite()).toEqual(['mesocycle_build', { mesocycle_id: ID, days: expected }]);
            expect(inputViolations(...lastWrite())).toEqual([]);
        });

        it('MESO-013 picking the sets sends every day with only that slot\'s sets changed', async () => {
            const wrapper = slots();

            await choose(wrapper, 'Chest sets', '4 sets');

            const expected = allDays();
            expected[0]!.slots[0]!.sets = 4;
            expect(lastWrite()).toEqual(['mesocycle_build', { mesocycle_id: ID, days: expected }]);
            expect(inputViolations(...lastWrite())).toEqual([]);
        });

        it('MESO-013 MESO-016 Pick for me sends a valid mesocycle_slots_fill', async () => {
            const wrapper = slots();

            await click(wrapper, 'button[aria-label="Pick for me"]');

            expect(lastWrite()).toEqual(['mesocycle_slots_fill', { mesocycle_id: ID }]);
            expect(inputViolations(...lastWrite())).toEqual([]);
        });

        it('MESO-013 turns Pick for me off when the server says there is nothing to fill', async () => {
            const full = structuredClone(warning);
            full.build!.can_pick_for_me = false;
            const wrapper = show({ payload: full });

            expect(wrapper.find('button[aria-label="Pick for me"]').attributes('disabled')).toBeDefined();
            await click(wrapper, 'button[aria-label="Pick for me"]');
            expect(writeMock).not.toHaveBeenCalled();
        });

        it('MESO-014 adds an exercise for a muscle at the end of the day, with a new id, no exercise and two sets', async () => {
            const wrapper = slots();
            const day = wrapper.findAll('[data-test="day"]')[1]!;

            await day.find('[data-test="menu"]').trigger('click');
            expect(day.findAll('[role="menuitem"]')).toHaveLength(14);
            await day.find('[data-test="item-biceps"]').trigger('click');
            await flushPromises();

            const [name, input] = lastWrite();
            expect(name).toBe('mesocycle_build');
            const days = input.days as ReturnType<typeof allDays>;
            const added = days[1]!.slots.at(-1)!;
            expect(added).toEqual({ slot_id: expect.stringMatching(ULID), muscle: 'biceps', exercise_id: null, sets: 2 });
            expect(days[1]!.slots).toHaveLength(warning.build!.days[1]!.slots.length + 1);
            expect(days.map((d) => d.slots.length)).toEqual([6, 7, 6, 6]);
            const untouched = allDays();
            expect(days[0]).toEqual(untouched[0]);
            expect(days[1]!.slots.slice(0, -1)).toEqual(untouched[1]!.slots);
            expect(days[2]).toEqual(untouched[2]);
            expect(inputViolations(name, input)).toEqual([]);
        });

        it('MESO-014 deletes one exercise and keeps every other slot as it was', async () => {
            const wrapper = slots();
            const row = wrapper.findAll('[data-test="day"]')[0]!.findAll('[data-test="slot"]')[1]!;

            await row.find('button[aria-label="Delete Quads"]').trigger('click');
            await flushPromises();

            const expected = allDays();
            expected[0]!.slots.splice(1, 1);
            expect(lastWrite()).toEqual(['mesocycle_build', { mesocycle_id: ID, days: expected }]);
            expect(inputViolations(...lastWrite())).toEqual([]);
        });

        it('CAP-012 says the draft could not be saved, with the server\'s words', async () => {
            writeMock.mockRejectedValueOnce(new CapabilityError('mesocycle_build', 422, { error: { message: 'That exercise is not for this muscle.' } }));
            const wrapper = slots();

            await choose(wrapper, 'Chest exercise', 'Cable Fly');

            expect(wrapper.find('[role="alert"]').text()).toBe('Your draft could not be saved: That exercise is not for this muscle.');
        });

        it('CAP-010 turns off every control while a write runs, so a second tap sends nothing', async () => {
            let answer: (value: unknown) => void = () => {};
            writeMock.mockImplementationOnce(() => new Promise((resolve) => (answer = resolve)));
            const wrapper = slots();

            await choose(wrapper, 'Chest sets', '4 sets');
            const controls = [...wrapper.findAll('[data-test="slot"] select'), ...wrapper.findAll('[data-test="slot"] button'), ...wrapper.findAll('[data-test="menu"]'), wrapper.find('button[aria-label="Pick for me"]'), wrapper.find(next), wrapper.find(back)];
            expect(controls.length).toBeGreaterThan(60);
            expect(controls.every((c) => c.attributes('disabled') !== undefined)).toBe(true);
            await click(wrapper, 'button[aria-label="Pick for me"]');
            expect(writeMock).toHaveBeenCalledTimes(1);

            answer({});
            await flushPromises();
            expect(wrapper.find('select[aria-label="Chest sets"]').attributes('disabled')).toBeUndefined();
            expect(wrapper.find(next).attributes('disabled')).toBeUndefined();
        });

        it('OFF-008 dims the sets and shows Waiting to sync while a change waits', () => {
            const waiting = show({ payload: warning, pending: true });

            expect(waiting.text()).toContain('Waiting to sync');
            expect(waiting.find('[data-test="sets"]').classes()).toContain('opacity-60');
            const idle = show({ payload: warning });
            expect(idle.text()).not.toContain('Waiting to sync');
            expect(idle.find('[data-test="sets"]').classes()).not.toContain('opacity-60');
        });

        it('MESO-013 Continue goes to the Details step without a write, and Back to Meso Builder', async () => {
            const wrapper = slots();
            expect(wrapper.find(back).attributes('disabled')).toBeUndefined();

            await click(wrapper, next);
            expect(wrapper.find('h2').text()).toBe('Details');
            expect(writeMock).not.toHaveBeenCalled();

            await click(wrapper, back);
            expect(wrapper.find('h2').text()).toBe('Exercises');
            await click(wrapper, back);
            expect(wrapper.find('h2').text()).toBe('Meso Builder');
            expect(wrapper.find(back).attributes('disabled')).toBeDefined();
        });
    });

    describe('Details step', () => {
        const details = (build: Partial<NonNullable<MesocycleShowOutput['build']>> = {}, name: string | null = warning.name ?? null) => {
            const filled = structuredClone(warning);
            filled.name = name;
            filled.build = { ...filled.build!, ...build };
            return show({ payload: filled, step: 'details' });
        };

        it('MESO-004 shows the name, length, start, labels and what the phase sets', () => {
            const wrapper = details({ phase_warning: { text: 'No phase covers this date.', tone: 'warning' } });

            expect(wrapper.find('h2').text()).toBe('Details');
            expect(wrapper.text()).toContain('Step 3 of 3');
            expect((wrapper.find('input[aria-label="Name"]').element as HTMLInputElement).value).toBe('Hypertrophy Block 3');
            expect((wrapper.find('select[aria-label="Weeks"]').element as HTMLSelectElement).selectedOptions[0]!.text).toBe('5 weeks');
            expect((wrapper.find('input[aria-label="Start"]').element as HTMLInputElement).value).toBe('2026-10-12');
            expect(wrapper.text()).toContain('12 Oct 2026');
            expect(wrapper.text()).toContain('The last week is the deload. A mesocycle starts on the first day of a plan week.');
            expect(wrapper.text()).toContain('No phase covers this date.');
            expect(wrapper.text()).toContain('From the phase');
            expect(wrapper.text()).toContain('Muscle gain');
            expect(wrapper.text()).toContain('Mixed rep ranges, with more 5–10');
            expect(wrapper.find('[role="radiogroup"]').attributes('aria-label')).toBe('Day labels');
        });

        it('MESO-003 says None on this date when no phase covers the start', () => {
            const wrapper = details({ phase_title: null, rep_range_text: null });

            expect(wrapper.text()).toContain('None on this date');
        });

        it('MESO-004 saves the name when the field is left, with a valid mesocycle_build', async () => {
            const wrapper = details();
            const field = wrapper.find('input[aria-label="Name"]');

            await field.setValue('Summer Cut');
            await field.trigger('change');
            await flushPromises();

            expect(lastWrite()).toEqual(['mesocycle_build', { mesocycle_id: ID, name: 'Summer Cut' }]);
            expect(inputViolations(...lastWrite())).toEqual([]);
        });

        it('MESO-004 saves the name after a typing pause of 400 ms, once', async () => {
            vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
            onTestFinished(() => void vi.useRealTimers());
            const wrapper = details();
            const field = wrapper.find('input[aria-label="Name"]');

            await field.setValue('Sum');
            vi.advanceTimersByTime(300);
            await field.setValue('Summer');
            vi.advanceTimersByTime(399);
            expect(writeMock).not.toHaveBeenCalled();
            vi.advanceTimersByTime(1);
            await flushPromises();

            expect(sent()).toEqual(['mesocycle_build']);
            expect(lastWrite()[1]).toEqual({ mesocycle_id: ID, name: 'Summer' });
        });

        it('MESO-004 sends no name once the screen is gone', async () => {
            vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
            onTestFinished(() => void vi.useRealTimers());
            const wrapper = details();

            await wrapper.find('input[aria-label="Name"]').setValue('Sum');
            wrapper.unmount();
            vi.advanceTimersByTime(1000);

            expect(writeMock).not.toHaveBeenCalled();
        });

        it('MESO-004 sends the length, the start date and the day labels as valid writes', async () => {
            const wrapper = details();

            await choose(wrapper, 'Weeks', '7 weeks');
            expect(lastWrite()).toEqual(['mesocycle_build', { mesocycle_id: ID, weeks: 7 }]);
            expect(inputViolations(...lastWrite())).toEqual([]);

            const date = wrapper.find('input[aria-label="Start"]');
            (date.element as HTMLInputElement).value = '2026-10-19';
            await date.trigger('change');
            await flushPromises();
            expect(lastWrite()).toEqual(['mesocycle_build', { mesocycle_id: ID, start_date: '2026-10-19' }]);
            expect(inputViolations(...lastWrite())).toEqual([]);

            await wrapper.find('[role="radio"][aria-label="Day 1, Day 2"]').trigger('click');
            await flushPromises();
            expect(lastWrite()).toEqual(['mesocycle_build', { mesocycle_id: ID, label_style: 'floating' }]);
        });

        it('MESO-004 Create mesocycle sends finish with the name in the field, then opens the mesocycle', async () => {
            const wrapper = details({ can_finish: true });
            await wrapper.find('input[aria-label="Name"]').setValue('Summer Cut');
            writeMock.mockClear();

            expect(wrapper.find(next).text()).toBe('Create mesocycle');
            await click(wrapper, next);

            expect(lastWrite()).toEqual(['mesocycle_build', { mesocycle_id: ID, name: 'Summer Cut', finish: true }]);
            expect(inputViolations(...lastWrite())).toEqual([]);
            expect(router.replace).toHaveBeenCalledWith(`/train/mesocycles/${ID}`);
        });

        it('MESO-004 turns Create mesocycle off, with a hint, while the server says it cannot finish', async () => {
            const wrapper = details({ can_finish: false });

            expect(wrapper.find(next).attributes('disabled')).toBeDefined();
            expect(wrapper.text()).toContain('Name it and start it in a phase to create it.');
            await click(wrapper, next);
            expect(writeMock).not.toHaveBeenCalled();
        });

        it('CAP-012 shows the server\'s reason when it refuses to create the mesocycle, and does not leave', async () => {
            writeMock.mockRejectedValueOnce(new CapabilityError('mesocycle_build', 422, { error: { message: 'Give it a name first.' } }));
            const wrapper = details({ can_finish: true });

            await click(wrapper, next);

            expect(wrapper.find('[role="alert"]').text()).toBe('Give it a name first.');
            expect(router.replace).not.toHaveBeenCalled();
        });
    });

    describe('Starting a build', () => {
        it('MESO-002 Meso Builder: makes the draft once with a valid mesocycle_build, keeps it in the address and reads it', async () => {
            draft = empty;
            const wrapper = show({ route: 'builder' });
            await flushPromises();

            const [name, input] = writeMock.mock.calls[0]!;
            expect(name).toBe('mesocycle_build');
            expect(input).toEqual({ mesocycle_id: expect.stringMatching(ULID), route: 'builder' });
            expect(inputViolations(name, input)).toEqual([]);
            expect(router.replace).toHaveBeenCalledWith({ query: { draft: (input as { mesocycle_id: string }).mesocycle_id } });
            expect(readMock).toHaveBeenCalledWith('mesocycle_show', { mesocycle_id: (input as { mesocycle_id: string }).mesocycle_id }, expect.any(Function), expect.any(Function));
            expect(writeMock).toHaveBeenCalledTimes(1);
            expect(wrapper.find('h2').text()).toBe('Meso Builder');
        });

        it('MESO-002 Build from scratch: makes the draft with route scratch and opens the scratch step', async () => {
            draft = { ...empty, build: { ...empty.build!, route: 'scratch', resume_step: 'scratch' } };
            const wrapper = show({ route: 'scratch' });
            await flushPromises();

            expect(lastWrite()[1]).toEqual({ mesocycle_id: expect.stringMatching(ULID), route: 'scratch' });
            expect(inputViolations(...lastWrite())).toEqual([]);
            expect(wrapper.find('h2').text()).toBe('Build from scratch');
        });

        it('MESO-002 Copy previous: copies the source with a valid mesocycle_copy and opens on the exercises, with no Back', async () => {
            draft = { ...warning, build: { ...warning.build!, route: 'copy_previous' } };
            const wrapper = show({ route: 'copy_previous', copy: saved.id });
            await flushPromises();

            expect(lastWrite()).toEqual(['mesocycle_copy', { mesocycle_id: expect.stringMatching(ULID), source_mesocycle_id: saved.id }]);
            expect(inputViolations(...lastWrite())).toEqual([]);
            expect(wrapper.find('h2').text()).toBe('Exercises');
            expect(wrapper.text()).toContain('Step 1 of 2');
            expect(wrapper.find(back).attributes('disabled')).toBeDefined();

            await click(wrapper, next);
            expect(wrapper.text()).toContain('Step 2 of 2');
            await click(wrapper, back);
            expect(wrapper.find('h2').text()).toBe('Exercises');
        });

        it('CAP-014 shows why the draft could not be made, and Try again sends the same draft again', async () => {
            writeMock.mockRejectedValueOnce(new CapabilityError('mesocycle_build', 422, { error: { message: 'You cannot start a build now.' } }));
            draft = empty;
            const wrapper = show({ route: 'builder' });
            await flushPromises();

            expect(wrapper.find('[role="alert"]').text()).toContain('You cannot start a build now.');
            expect(router.replace).not.toHaveBeenCalled();
            await click(wrapper, '[data-test="retry"]');

            expect(writeMock).toHaveBeenCalledTimes(2);
            expect(writeMock.mock.calls[1]![1]).toEqual(writeMock.mock.calls[0]![1]);
            expect(wrapper.find('h2').text()).toBe('Meso Builder');
        });

        it('PWA-024 shows a skeleton while the draft is made', async () => {
            writeMock.mockImplementationOnce(() => new Promise(() => {}));
            const wrapper = show({ route: 'builder' });
            await flushPromises();

            expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        });

        it('MESO-002 Template: shows the library first, makes nothing, and Back is off', async () => {
            const wrapper = show({ route: 'template' });
            await flushPromises();

            expect(wrapper.find('h2').text()).toBe('Choose a template');
            expect(wrapper.text()).toContain('Step 1 of 3');
            expect(wrapper.findAll('[data-test="template"]')).toHaveLength(8);
            expect(writeMock).not.toHaveBeenCalled();
            expect(readMock).not.toHaveBeenCalledWith('mesocycle_show', expect.anything(), expect.anything(), expect.anything());
            expect(wrapper.find(next).attributes('disabled')).toBeDefined();
            expect(wrapper.text()).toContain('Pick a template to continue.');
            expect(wrapper.find(back).attributes('disabled')).toBeDefined();
        });

        it('MESO-012 MESO-013 a tapped template shows its detail, Back returns to the library, and Use this template starts the draft from it', async () => {
            const wrapper = show({ route: 'template' });
            await flushPromises();

            await wrapper.findAll('[data-test="template"]')[0]!.trigger('click');
            await flushPromises();
            expect(wrapper.text()).toContain('Full Body A');
            expect(wrapper.find('h1').text()).toBe('New mesocycle');
            expect(readMock).toHaveBeenCalledWith('templates_show', { template_id: '01J9TPL0000000000000000000' }, expect.any(Function), expect.any(Function));
            await click(wrapper, back);
            expect(wrapper.findAll('[data-test="template"]')).toHaveLength(8);

            await wrapper.findAll('[data-test="template"]')[0]!.trigger('click');
            await flushPromises();
            expect(wrapper.find(next).text()).toBe('Use this template');
            await click(wrapper, next);

            const [name, input] = writeMock.mock.calls[0]!;
            expect(name).toBe('mesocycle_build');
            expect(input).toEqual({ mesocycle_id: expect.stringMatching(ULID), route: 'template', template_id: '01J9TPL0000000000000000000' });
            expect(inputViolations(name, input)).toEqual([]);
            expect(router.replace).toHaveBeenCalledWith({ query: { draft: (input as { mesocycle_id: string }).mesocycle_id } });
            expect(wrapper.find('h2').text()).toBe('Exercises');
            expect(wrapper.text()).toContain('Step 2 of 3');
        });

        it('CAP-012 keeps the library and says why when the template could not be used', async () => {
            writeMock.mockRejectedValueOnce(new CapabilityError('mesocycle_build', 422, { error: { message: 'That template is gone.' } }));
            const wrapper = show({ route: 'template' });
            await flushPromises();
            await wrapper.findAll('[data-test="template"]')[0]!.trigger('click');
            await flushPromises();

            await click(wrapper, next);

            expect(wrapper.find('[role="alert"]').text()).toBe('That template is gone.');
            expect(wrapper.find('h2').text()).toBe('Choose a template');
            expect(router.replace).not.toHaveBeenCalled();
        });

        it('MESO-007 opens the unfinished draft where it was left, with no write', async () => {
            const wrapper = show({ draft: ID });
            expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
            await flushPromises();

            expect(readMock).toHaveBeenCalledWith('mesocycle_show', { mesocycle_id: ID }, expect.any(Function), expect.any(Function));
            expect(writeMock).not.toHaveBeenCalled();
            expect(wrapper.find('h2').text()).toBe('Exercises');
        });

        it('MESO-007 a step in the address wins over where the draft was left', async () => {
            const wrapper = show({ draft: ID, step: 'details' });
            await flushPromises();

            expect(wrapper.find('h2').text()).toBe('Details');
        });

        it('CAP-002 says the draft could not be loaded, and Try again reads it', async () => {
            readMock.mockRejectedValueOnce(new CapabilityError('mesocycle_show', 500, null));
            const wrapper = show({ draft: ID });
            await flushPromises();

            expect(wrapper.text()).toContain('The mesocycle could not be loaded.');
            await click(wrapper, '[data-test="retry"]');
            expect(wrapper.find('h2').text()).toBe('Exercises');
        });

        it('CAP-004 says when the user is signed out', async () => {
            readMock.mockRejectedValueOnce(new CapabilityError('mesocycle_show', 401, null));
            const wrapper = show({ draft: ID });
            await flushPromises();

            expect(wrapper.text()).toContain('You are signed out.');
        });

        it('MESO-004 opens the mesocycle itself when the draft read answers with a saved one', async () => {
            draft = saved;
            const wrapper = show({ draft: saved.id });
            await flushPromises();

            expect(router.replace).toHaveBeenCalledWith(`/train/mesocycles/${saved.id}`);
            expect(wrapper.find('h2').exists()).toBe(false);
        });

        it('MESO-002 says there is nothing to build when no way to build is given', () => {
            for (const props of [{}, { route: 'nonsense' }, { route: 'copy_previous' }]) {
                const wrapper = show(props);

                expect(wrapper.text()).toContain('Nothing to build.');
                expect(wrapper.findAllComponents(RouterLinkStub).some((l) => l.props('to') === '/train/mesocycles')).toBe(true);
            }
            expect(writeMock).not.toHaveBeenCalled();
        });
    });

    describe('Close', () => {
        it('NAV-001 goes back when the app has somewhere to go back to', async () => {
            window.history.replaceState({ back: '/train/mesocycles' }, '');
            onTestFinished(() => window.history.replaceState(null, ''));
            const wrapper = show();

            await click(wrapper, 'button[aria-label="Close"]');

            expect(router.back).toHaveBeenCalled();
            expect(router.replace).not.toHaveBeenCalled();
        });

        it('NAV-001 goes to the mesocycles when it was opened directly', async () => {
            window.history.replaceState(null, '');
            const wrapper = show();

            await click(wrapper, 'button[aria-label="Close"]');

            expect(router.replace).toHaveBeenCalledWith('/train/mesocycles');
            expect(router.back).not.toHaveBeenCalled();
        });
    });
});
