import 'fake-indexeddb/auto';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive, ref } from 'vue';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import { CapabilityError, read, write } from '@/app/call';
import { isPending, pendingInput } from '@/app/outbox';
import type { WorkoutShowOutput } from '@/contracts/workout_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import { provideScreenTop } from '@/app/screenTop';
import { useHoldUpdates } from '@/app/updates';
import { activeWorkout } from './activeWorkout';
import { holdWakeLock, leaveWakeLock, releaseWakeLock } from './wakeLock';
import WorkoutView from './WorkoutView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), write: vi.fn() }));
// The outbox's entries, reactive, so a test can put a refused entry in after the screen mounts.
const { tray } = await vi.hoisted(async () => ({ tray: (await import('vue')).ref<unknown[]>([]) }));
vi.mock('@/app/outbox', async (original) => ({
    ...(await original<typeof import('@/app/outbox')>()),
    isPending: vi.fn(() => false),
    pendingInput: vi.fn(() => undefined),
    entries: tray,
}));
vi.mock('./wakeLock', () => ({ holdWakeLock: vi.fn(), leaveWakeLock: vi.fn(), releaseWakeLock: vi.fn() }));
vi.mock('@/app/updates', () => ({ useHoldUpdates: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(write);

const workout = (state?: string) => screenFixture<WorkoutShowOutput>(state ? `workout_show.${state}` : 'workout_show');

const BENCH = '01JB8W3Q000000000000000010';
const ROW = '01JB8W3Q000000000000000011';
const BENCH_SET_1 = '01JB8W3Q000000000000000100';

let router: Router;

async function show(props: Record<string, unknown> = { payload: workout() }): Promise<VueWrapper> {
    router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:any(.*)*', component: { template: '<p />' } }] });
    await router.push('/workouts/01JB8W3Q000000000000000900/3/1');
    const wrapper = mount(WorkoutView, { props, attachTo: document.body, global: { plugins: [router], stubs: { teleport: true } } });
    await flushPromises();
    return wrapper;
}

/** Each [name, input] sent through the outbox, checked against that write's schema snapshot. */
function sent(): [string, Record<string, unknown>][] {
    return writeMock.mock.calls.map(([name, input]) => {
        expect(inputViolations(name, input), name).toEqual([]);
        return [name, input as Record<string, unknown>];
    });
}

const exercise = (wrapper: VueWrapper, id: string) => wrapper.get(`[data-exercise="${id}"]`);
const setRow = (wrapper: VueWrapper, id: string) => wrapper.get(`[data-set="${id}"]`);

async function menu(wrapper: VueWrapper, scope: string, key: string): Promise<void> {
    await wrapper.get(`${scope} [data-test="menu"]`).trigger('click');
    await wrapper.get(`${scope} [data-test="item-${key}"]`).trigger('click');
    await flushPromises();
}

/** Opens the set's menu sheet from its row and taps one of its actions. */
async function setAction(wrapper: VueWrapper, setId: string, action: string): Promise<void> {
    await wrapper.get(`[data-set="${setId}"] [data-test="menu"]`).trigger('click');
    await wrapper.get(`[data-action="${action}"]`).trigger('click');
    await flushPromises();
}

/** Opens the exercise's menu and picks an item. */
async function exerciseItem(wrapper: VueWrapper, id: string, key: string): Promise<void> {
    await exercise(wrapper, id).get('[data-test="menu"]').trigger('click');
    await exercise(wrapper, id).get(`[data-test="item-${key}"]`).trigger('click');
    await flushPromises();
}

const dialog = (wrapper: VueWrapper) => wrapper.find('[role="dialog"] h2').exists() ? wrapper.get('[role="dialog"] h2').text() : null;

describe('WorkoutView', () => {
    beforeEach(() => {
        document.cookie = 'bb_cache_owner=a1; path=/';
        localStorage.clear();
        readMock.mockReset().mockReturnValue(new Promise(() => {}));
        writeMock.mockReset().mockResolvedValue({} as never);
        vi.mocked(isPending).mockReturnValue(false);
        vi.mocked(pendingInput).mockReturnValue(undefined);
        tray.value = [];
        vi.mocked(holdWakeLock).mockClear();
        vi.mocked(leaveWakeLock).mockClear();
        vi.mocked(releaseWakeLock).mockClear();
        document.body.innerHTML = '';
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('WO-001 shows the session title, then each exercise in order with its sets', async () => {
        const wrapper = await show();

        expect(wrapper.get('h1').text()).toBe('Week 3 · Day 1 · Upper A · Mon 28 Sep');
        expect(wrapper.findAll('[data-exercise]').map((card) => card.get('h2').text())).toEqual(['Bench Press', 'Barbell Row']);
        expect(exercise(wrapper, BENCH).findAll('[data-set]')).toHaveLength(3);
        expect(exercise(wrapper, ROW).findAll('[data-set]')).toHaveLength(3);
    });

    it('WO-002 each exercise header shows name, equipment and rep range, its pinned note, a history button and a menu', async () => {
        const wrapper = await show();

        const row = exercise(wrapper, ROW);
        expect(row.text()).toContain('Barbell · 5–10 reps');
        expect(row.get('[data-test="pinned-note"]').text()).toBe('Chest up, pull to the belly button');
        expect(row.get('[data-test="history"]').attributes('href')).toBe(`/progress/exercises/${workout().exercises[1]!.exercise_id}`);
        expect(row.get('[data-test="menu"]').attributes('aria-label')).toBe('Barbell Row menu');
        expect(exercise(wrapper, BENCH).find('[data-test="pinned-note"]').exists()).toBe(false);
    });

    it('WO-024 shows the warm-up guidance on the first exercise only, until Got it', async () => {
        const wrapper = await show();

        const warmUp = exercise(wrapper, BENCH).get('[data-test="warm-up"]');
        expect(warmUp.findAll('li').map((li) => li.text())).toEqual(workout().warm_up!.steps.map((step) => step.text));
        expect(warmUp.text()).toContain('Warm-up sets are not logged.');
        expect(exercise(wrapper, ROW).find('[data-test="warm-up"]').exists()).toBe(false);

        await warmUp.get('button').trigger('click');

        expect(wrapper.find('[data-test="warm-up"]').exists()).toBe(false);
    });

    it('WO-006 in week 1 the first set\'s weight carries to the next sets, which can be overwritten', async () => {
        const payload = workout();
        const bench = payload.exercises[0]!;
        bench.carries_first_weight = true;
        for (const set of bench.sets) Object.assign(set, { weight: null, reps: null, target_weight: null, target_text: null, rep_table: [], off_table_text: null });
        const wrapper = await show({ payload });

        await setRow(wrapper, bench.sets[0]!.id).get('[data-test="weight"]').setValue('60');
        await setRow(wrapper, bench.sets[2]!.id).get('[data-test="weight"]').setValue('55');

        expect((setRow(wrapper, bench.sets[1]!.id).get('[data-test="weight"]').element as HTMLInputElement).value).toBe('60');
        expect((setRow(wrapper, bench.sets[2]!.id).get('[data-test="weight"]').element as HTMLInputElement).value).toBe('55');
    });

    it('WO-006 a weight does not carry where the server says it does not', async () => {
        const payload = workout();
        for (const set of payload.exercises[0]!.sets.slice(1)) Object.assign(set, { weight: null, rep_table: [] });
        const wrapper = await show({ payload });

        await setRow(wrapper, payload.exercises[0]!.sets[0]!.id).get('[data-test="weight"]').setValue('60');

        expect((setRow(wrapper, payload.exercises[0]!.sets[1]!.id).get('[data-test="weight"]').element as HTMLInputElement).value).toBe('');
    });

    it('WO-009 shows the post-workout meal at the bottom', async () => {
        const wrapper = await show();

        expect(wrapper.get('[data-test="next-meal"]').text()).toBe('Next: Meal 3 at 7:00 PM · 95 g carbs');
    });

    it('WO-040 Finish is off until the server says the session can finish, with its feedback banner', async () => {
        const wrapper = await show({ payload: workout('warning') });

        expect(wrapper.get('[data-test="finish"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-test="feedback-banner"]').text()).toBe('Your feedback is required');
        expect(wrapper.get('[data-test="feedback-banner"]').classes()).toContain('text-tone-warning');
    });

    it("WO-002 shows the server's recovery note, swap suggestion and session note on their exercise (workout_show.warning)", async () => {
        const wrapper = await show({ payload: workout('warning') });

        expect(exercise(wrapper, BENCH).get('[data-test="recovery-note"]').text()).toContain('Chest missed its rep targets');
        expect(exercise(wrapper, ROW).get('[data-test="swap-suggestion"]').text()).toContain('Barbell Row hurt 2 sessions running');
        expect(exercise(wrapper, ROW).get('[data-test="session-note"]').text()).toBe('Superset with face pulls');
    });

    it('WO-001 says when the workout has no exercises (workout_show.empty)', async () => {
        const wrapper = await show({ payload: workout('empty') });

        expect(wrapper.get('[data-test="no-exercises"]').text()).toBe('This workout has no exercises.');
        expect(wrapper.find('[data-test="next-meal"]').exists()).toBe(false);

        await wrapper.get('[data-test="look-back"]').trigger('click');

        expect(wrapper.get('[data-test="none"]').text()).toBe('No earlier sessions in this mesocycle yet.');
    });

    it('CAP-002 reads the session it names, with a skeleton until it comes', async () => {
        const wrapper = await show({ mesocycleId: 'm1', week: 3, day: 1 });

        expect(readMock).toHaveBeenCalledWith('workout_show', { mesocycle_id: 'm1', week: 3, day: 1 }, expect.any(Function), expect.any(Function));
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
    });

    it('CAP-004 says when the workout could not load, and Try again reads again', async () => {
        readMock.mockRejectedValue(new CapabilityError('workout_show', 500, null));
        const wrapper = await show({ mesocycleId: 'm1', week: 3, day: 1 });

        expect(wrapper.get('[role="alert"]').text()).toContain('This workout could not be loaded.');
        await wrapper.get('[data-test="retry"]').trigger('click');
        await flushPromises();

        expect(readMock).toHaveBeenCalledTimes(2);
    });

    it('OFF-008 while a change waits to sync, the server-derived banner is dimmed and Finish says it is waiting', async () => {
        const wrapper = await show({ payload: workout('warning'), pending: true });

        expect(wrapper.get('[data-test="feedback-banner"] [data-value]').classes()).toContain('opacity-60');
        expect(wrapper.get('[data-test="finish-pending"]').text()).toContain('Waiting to sync');
    });

    it('OFF-008 shows nothing pending while the outbox holds nothing for the workout', async () => {
        const wrapper = await show({ payload: workout('warning') });

        expect(wrapper.find('[data-test="finish-pending"]').exists()).toBe(false);
        expect(isPending).toHaveBeenCalledWith('workout_show');
    });

    it('WO-010 the first change to a session not yet started starts it, then logs the set, through the outbox (OFF-006)', async () => {
        const wrapper = await show();

        await setRow(wrapper, BENCH_SET_1).get('[data-test="log"]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([
            ['workout_start', { workout_id: '01JB8W3Q000000000000000901' }],
            ['workout_set_log', { set_id: BENCH_SET_1, weight: { value: 82.5, unit: 'kg' }, reps: 10, is_logged: true }],
        ]);
        expect(writeMock.mock.calls[1]![2]).toEqual({ label: 'Logged Bench Press set 1: 82.5 kg × 10 reps', creates: undefined });
    });

    it('WO-010 a session already under way is not started again', async () => {
        const wrapper = await show({ payload: workout('warning') });

        await setRow(wrapper, '01JB8W3Q000000000000000100').get('[data-test="log"]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['workout_set_log', { set_id: '01JB8W3Q000000000000000100', weight: { value: 80, unit: 'kg' }, reps: 10, is_logged: false }]]);
    });

    it('WO-010 a start already waiting in the outbox is not sent twice', async () => {
        vi.mocked(pendingInput).mockImplementation(((name: string) => (name === 'workout_start' ? { workout_id: '01JB8W3Q000000000000000901' } : undefined)) as never);
        const wrapper = await show();

        await setRow(wrapper, BENCH_SET_1).get('[data-test="log"]').trigger('click');
        await flushPromises();

        expect(sent().map(([name]) => name)).toEqual(['workout_set_log']);
    });

    it('WO-003 a weight typed and left is saved before the Log tap that follows it', async () => {
        const wrapper = await show({ payload: workout('warning') });
        const row = setRow(wrapper, '01JB8W3Q000000000000000101');

        await row.get('[data-test="reps"]').setValue('9');
        await row.get('[data-test="reps"]').trigger('blur');
        await row.get('[data-test="log"]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([
            ['workout_set_log', { set_id: '01JB8W3Q000000000000000101', weight: { value: 80, unit: 'kg' }, reps: 9, is_logged: true }],
            ['workout_set_log', { set_id: '01JB8W3Q000000000000000101', weight: { value: 80, unit: 'kg' }, reps: 9, is_logged: false }],
        ]);
    });

    it('PWA-033 logging a set and finishing give a short haptic tap where the device has one', async () => {
        const vibrate = vi.fn();
        vi.stubGlobal('navigator', { ...navigator, vibrate });
        const finishing = { ...workout('warning'), can_finish: true, feedback_banner: null };
        const wrapper = await show({ payload: finishing });

        await setRow(wrapper, '01JB8W3Q000000000000000110').get('[data-test="log"]').trigger('click');
        await flushPromises();
        expect(vibrate).toHaveBeenCalledTimes(1);

        await wrapper.get('[data-test="finish"]').trigger('click');
        await flushPromises();
        expect(vibrate).toHaveBeenCalledTimes(2);
    });

    it('PWA-033 unlogging a set taps nothing', async () => {
        const vibrate = vi.fn();
        vi.stubGlobal('navigator', { ...navigator, vibrate });
        const wrapper = await show({ payload: workout('warning') });

        await setRow(wrapper, '01JB8W3Q000000000000000100').get('[data-test="log"]').trigger('click');
        await flushPromises();

        expect(sent()).toHaveLength(1);
        expect(vibrate).not.toHaveBeenCalled();
    });

    it('PWA-033 a device with no vibration (iOS) still logs the set', async () => {
        vi.stubGlobal('navigator', { userAgent: 'iPhone' });
        const wrapper = await show({ payload: workout('warning') });

        await setRow(wrapper, '01JB8W3Q000000000000000110').get('[data-test="log"]').trigger('click');
        await flushPromises();

        expect(sent().map(([name]) => name)).toEqual(['workout_set_log']);
        expect(wrapper.find('[data-test="error"]').exists()).toBe(false);
    });

    it('WO-010 Finish records the end of a session that can finish, and lets the wake lock go (PWA-030)', async () => {
        const wrapper = await show({ payload: { ...workout('warning'), can_finish: true } });

        await wrapper.get('[data-test="finish"]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['workout_finish', { workout_id: '01JB8W3Q000000000000000901' }]]);
        expect(releaseWakeLock).toHaveBeenCalledWith('01JB8W3Q000000000000000901');
    });

    it('OFF-006 says why a change could not be stored', async () => {
        writeMock.mockRejectedValue(new CapabilityError('workout_start', 401, null));
        const wrapper = await show();

        await setRow(wrapper, BENCH_SET_1).get('[data-test="log"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-test="error"]').text()).not.toBe('');
    });

    it('WO-020 the set menu opens as a sheet titled with the set, and Cancel closes it', async () => {
        const wrapper = await show({ payload: workout('warning') });

        await wrapper.get(`[data-set="${BENCH_SET_1}"] [data-test="menu"]`).trigger('click');
        expect(dialog(wrapper)).toBe('Set 1 · Bench Press');
        await wrapper.get('[data-cancel]').trigger('click');

        expect(dialog(wrapper)).toBeNull();
        expect(writeMock).not.toHaveBeenCalled();
    });

    it('WO-020 the set menu adds a set below, of its own type, under a new id (CAP-019)', async () => {
        const wrapper = await show({ payload: workout('warning') });

        await setAction(wrapper, BENCH_SET_1, 'add');

        const [name, input] = sent()[0]!;
        expect(name).toBe('workout_set_add');
        expect(input).toMatchObject({ workout_exercise_id: BENCH, after_set_id: BENCH_SET_1, set_type: 'regular_straight' });
        expect(writeMock.mock.calls[0]![2].creates).toEqual([input.set_id]);
        expect(dialog(wrapper)).toBeNull();
    });

    it('WO-020 the set menu skips a set, and unskips a skipped one', async () => {
        const payload = workout('warning');
        payload.exercises[1]!.sets[2]!.is_skipped = true;
        const wrapper = await show({ payload });

        await setAction(wrapper, BENCH_SET_1, 'skip');
        const skipped = payload.exercises[1]!.sets[2]!.id;
        expect(setRow(wrapper, skipped).classes()).toContain('opacity-50');
        expect(setRow(wrapper, skipped).get('[data-test="weight"]').attributes('disabled')).toBeDefined();
        await setAction(wrapper, skipped, 'skip');

        expect(sent()).toEqual([
            ['workout_set_skip', { set_id: BENCH_SET_1, is_skipped: true }],
            ['workout_set_skip', { set_id: skipped, is_skipped: false }],
        ]);
    });

    it('WO-020 Delete set asks first, then deletes it', async () => {
        const wrapper = await show({ payload: workout('warning') });

        await setAction(wrapper, BENCH_SET_1, 'delete');
        expect(writeMock).not.toHaveBeenCalled();
        expect(wrapper.get('[data-test="confirm"]').text()).toContain('Delete set 1?');
        await wrapper.get('[data-test="confirm"] [data-save]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['workout_set_delete', { set_id: BENCH_SET_1 }]]);
        expect(wrapper.find('[data-test="confirm"]').exists()).toBe(false);
    });

    it('WO-021 the set menu lists the server\'s set types and changes the set\'s type', async () => {
        const wrapper = await show({ payload: workout('warning') });

        await wrapper.get(`[data-set="${BENCH_SET_1}"] [data-test="menu"]`).trigger('click');
        const types = wrapper.get('[role="radiogroup"][aria-label="Set type"]').findAll('[role="radio"]');
        expect(types.map((item) => item.attributes('aria-label'))).toEqual(workout().set_type_choices.map((choice) => choice.label));
        expect(wrapper.get('[role="radio"][aria-checked="true"]').attributes('aria-label')).toBe('Regular · Straight');
        await wrapper.get('[role="radio"][aria-label="Myorep (M)"]').trigger('click');
        await wrapper.get('[data-save]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['workout_set_type_set', { set_id: BENCH_SET_1, set_type: 'myorep' }]]);
    });

    it('WO-007 the RIR icon on a set opens the RIR info sheet', async () => {
        const wrapper = await show();

        await setRow(wrapper, BENCH_SET_1).get('[data-test="rir-info"]').trigger('click');

        expect(dialog(wrapper)).toBe('Reps and RIR');
        expect(wrapper.text()).toContain("This week's target is 2 RIR");
        await wrapper.get('[data-cancel]').trigger('click');
        expect(dialog(wrapper)).toBeNull();
    });

    it('WO-021 a myorep set shows its badge', async () => {
        const payload = workout();
        Object.assign(payload.exercises[0]!.sets[1]!, { set_type: 'myorep_match', badge: 'MM' });
        const wrapper = await show({ payload });

        expect(setRow(wrapper, payload.exercises[0]!.sets[1]!.id).get('[data-test="badge"]').attributes('aria-label')).toBe('Myorep match');
    });

    it('WO-022 the exercise menu offers its items, Move up and Move down off where the server says', async () => {
        const wrapper = await show();

        await exercise(wrapper, BENCH).get('[data-test="menu"]').trigger('click');
        const items = exercise(wrapper, BENCH).findAll('[role="menuitem"]');
        expect(items.map((item) => item.find('span').text())).toEqual([
            'Add set: Regular',
            'Add set: Myorep (M)',
            'Add set: Myorep match (MM)',
            'Swap exercise',
            'Pin note',
            'Add note',
            'Move up',
            'Move down',
            'Remove exercise',
        ]);
        expect(exercise(wrapper, BENCH).get('[data-test="item-up"]').attributes('disabled')).toBeDefined();
        expect(exercise(wrapper, BENCH).get('[data-test="item-down"]').attributes('disabled')).toBeUndefined();
    });

    it('WO-022 Swap exercise, Pin note and Add note are on, and each opens its sheet', async () => {
        readMock.mockImplementation(((name: string) => (name === 'exercises_show' ? Promise.resolve(screenFixture('exercises_show')) : new Promise(() => {}))) as never);
        const wrapper = await show({ payload: workout('warning') });
        await exercise(wrapper, BENCH).get('[data-test="menu"]').trigger('click');
        for (const key of ['swap', 'pin', 'note']) expect(exercise(wrapper, BENCH).get(`[data-test="item-${key}"]`).attributes('disabled')).toBeUndefined();
        await exercise(wrapper, BENCH).get('[data-test="item-swap"]').trigger('click');
        await flushPromises();
        expect(dialog(wrapper)).toBe('Swap exercise');
        await wrapper.get('[data-cancel]').trigger('click');
        await exerciseItem(wrapper, BENCH, 'pin');
        expect(dialog(wrapper)).toBe('Pin note');
        await wrapper.get('[data-cancel]').trigger('click');
        await exerciseItem(wrapper, BENCH, 'note');
        expect(dialog(wrapper)).toBe('Add note');
    });

    it('EX-010 a pinned note saved from its sheet goes through the outbox, for the library exercise', async () => {
        const wrapper = await show({ payload: workout('warning') });

        await exerciseItem(wrapper, ROW, 'pin');
        await wrapper.get('textarea').setValue('Elbows tucked');
        await wrapper.get('[data-save]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['exercise_note_set', { exercise_id: workout().exercises[1]!.exercise_id, note: 'Elbows tucked' }]]);
        expect(dialog(wrapper)).toBeNull();
    });

    it('EX-008 the swap suggestion\'s Swap button opens the swap sheet', async () => {
        const library = screenFixture<{ exercises: Record<string, unknown>[] }>('exercises_show');
        library.exercises.push({ ...library.exercises[0]!, id: '01JA8Q0V3K2M4N6P8R0T2W4Y99', name: 'Chest Supported Row', detail: 'Back · Machine', muscle_group: 'back' });
        readMock.mockImplementation(((name: string) => (name === 'exercises_show' ? Promise.resolve(library) : new Promise(() => {}))) as never);
        const wrapper = await show({ payload: workout('warning') });

        const swap = exercise(wrapper, ROW).get('[data-test="swap-suggestion"] button');
        expect(swap.attributes('disabled')).toBeUndefined();
        await swap.trigger('click');
        await flushPromises();

        expect(dialog(wrapper)).toBe('Swap exercise');
        await wrapper.get('[role="radio"][aria-label="Rest of the mesocycle"]').trigger('click');
        await wrapper.get('[data-test="exercise"]').trigger('click');
        await flushPromises();
        expect(sent()[0]![0]).toBe('exercise_swap');
        expect(sent()[0]![1]).toMatchObject({ workout_exercise_id: ROW, scope: 'rest_of_mesocycle' });
    });

    it('WO-022 Add set adds the chosen type at the end of the exercise', async () => {
        const wrapper = await show({ payload: workout('warning') });

        await menu(wrapper, `[data-exercise="${ROW}"]`, 'add:myorep_match');

        const [name, input] = sent()[0]!;
        expect(name).toBe('workout_set_add');
        expect(input).toMatchObject({ workout_exercise_id: ROW, after_set_id: workout().exercises[1]!.sets[2]!.id, set_type: 'myorep_match' });
    });

    it('WO-022 Move down and Move up move the exercise one place in this session', async () => {
        const wrapper = await show({ payload: workout('warning') });

        await menu(wrapper, `[data-exercise="${BENCH}"]`, 'down');
        await menu(wrapper, `[data-exercise="${ROW}"]`, 'up');

        expect(sent()).toEqual([
            ['workout_exercise_reorder', { workout_exercise_id: BENCH, position: 2, remaining_weeks: false }],
            ['workout_exercise_reorder', { workout_exercise_id: ROW, position: 1, remaining_weeks: false }],
        ]);
    });

    it('WO-022 Remove exercise asks first, then removes it', async () => {
        const wrapper = await show({ payload: workout('warning') });

        await menu(wrapper, `[data-exercise="${ROW}"]`, 'remove');
        expect(wrapper.get('[data-test="confirm"]').text()).toContain('Its sets are deleted.');
        await wrapper.get('[data-test="confirm"] [data-save]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['workout_exercise_remove', { workout_exercise_id: ROW }]]);
    });

    it('WO-008 the calendar opens the look back sheet with the earlier sessions', async () => {
        const wrapper = await show();

        expect(wrapper.get('[data-test="look-back"]').attributes('aria-label')).toBe('Look back');
        await wrapper.get('[data-test="look-back"]').trigger('click');

        expect(dialog(wrapper)).toBe('Look back');
        const items = wrapper.findAll('[data-test="session"]');
        expect(items).toHaveLength(workout().look_back.length);
        expect(items[0]!.text()).toBe('Week 2 · Day 2 · Lower ADone');
        await wrapper.get('[data-cancel]').trigger('click');
        expect(dialog(wrapper)).toBeNull();
    });

    it('WO-008 a session picked in the look back sheet reads that session', async () => {
        const wrapper = await show();

        await wrapper.get('[data-test="look-back"]').trigger('click');
        await wrapper.findAll('[data-test="session"]')[0]!.trigger('click');

        expect(readMock).toHaveBeenCalledWith('workout_show', { mesocycle_id: '01JB8W3Q000000000000000900', week: 2, day: 2 }, expect.any(Function), expect.any(Function));
    });

    it('WO-030 the ? icon opens the workout help sheet', async () => {
        const wrapper = await show();

        await wrapper.get('[data-test="help"]').trigger('click');

        expect(dialog(wrapper)).toBe('Workout help');
        expect(wrapper.findAll('[data-test="section"]')).toHaveLength(workout().help.length);
        await wrapper.get('[data-cancel]').trigger('click');
        expect(dialog(wrapper)).toBeNull();
    });

    it('WO-031 the workout menu offers Add exercise, Reorder exercises, Skip workout, Progression rates and Relabel day, in order', async () => {
        const wrapper = await show();

        await wrapper.get('[data-test="day-menu"] [data-test="menu"]').trigger('click');

        expect(wrapper.findAll('[data-test="day-menu"] [role="menuitem"]').map((item) => item.find('span').text())).toEqual([
            'Add exercise',
            'Reorder exercises',
            'Skip workout',
            'Progression rates',
            'Relabel day',
        ]);
    });

    it('WO-031 Add exercise opens the exercise picker, and a pick is added to the session through the outbox', async () => {
        const library = screenFixture<{ exercises: { id: string }[] }>('exercises_show');
        readMock.mockImplementation(((name: string) => (name === 'exercises_show' ? Promise.resolve(library) : new Promise(() => {}))) as never);
        const wrapper = await show({ payload: workout('warning') });

        await wrapper.get('[data-test="day-menu"] [data-test="menu"]').trigger('click');
        await wrapper.get('[data-test="item-add"]').trigger('click');
        await flushPromises();
        expect(dialog(wrapper)).toBe('Add exercise');
        await wrapper.get('[data-test="exercise"]').trigger('click');
        await flushPromises();

        expect(sent()[0]![0]).toBe('workout_exercise_add');
        expect(sent()[0]![1]).toMatchObject({ workout_id: '01JB8W3Q000000000000000901', exercise_id: library.exercises[0]!.id, remaining_weeks: false });
        expect(dialog(wrapper)).toBeNull();
    });

    it('WO-031 Reorder exercises opens the reorder sheet, and a move goes through the outbox', async () => {
        const wrapper = await show({ payload: workout('warning') });

        await wrapper.get('[data-test="day-menu"] [data-test="menu"]').trigger('click');
        await wrapper.get('[data-test="item-reorder"]').trigger('click');
        expect(dialog(wrapper)).toBe('Reorder exercises');
        await wrapper.get('[aria-label="Move Bench Press down"]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['workout_exercise_reorder', { workout_exercise_id: BENCH, position: 2, remaining_weeks: false }]]);
        expect(dialog(wrapper)).toBe('Reorder exercises');
    });

    it('WO-050 Skip workout asks first, then skips it without starting the session', async () => {
        const wrapper = await show();

        await wrapper.get('[data-test="day-menu"] [data-test="menu"]').trigger('click');
        await wrapper.get('[data-test="item-skip"]').trigger('click');
        expect(writeMock).not.toHaveBeenCalled();
        expect(wrapper.get('[data-test="confirm"]').text()).toContain("Skip this workout? This can't be undone.");
        expect(wrapper.get('[data-test="confirm"] [data-save]').text()).toBe('Skip workout');
        await wrapper.get('[data-test="confirm"] [data-save]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['workout_skip', { workout_id: '01JB8W3Q000000000000000901' }]]);
    });

    it('MESO-045 Progression rates opens the mesocycle\'s rates screen', async () => {
        const wrapper = await show();

        await wrapper.get('[data-test="day-menu"] [data-test="menu"]').trigger('click');
        await wrapper.get('[data-test="item-rates"]').trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.fullPath).toBe('/train/mesocycles/01JB8W3Q000000000000000900/rates');
    });

    it('WO-031 Relabel day opens the relabel sheet, and the new name goes through the outbox', async () => {
        const wrapper = await show({ payload: workout('warning') });

        await wrapper.get('[data-test="day-menu"] [data-test="menu"]').trigger('click');
        await wrapper.get('[data-test="item-relabel"]').trigger('click');
        expect(dialog(wrapper)).toBe('Relabel day');
        await wrapper.get('input[aria-label="Day name"]').setValue('Push');
        await wrapper.get('[data-save]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['workout_day_relabel', { workout_id: '01JB8W3Q000000000000000901', label: 'Push' }]]);
    });

    it('WO-001 a session that can no longer change has no workout menu', async () => {
        const wrapper = await show({ payload: { ...workout('warning'), status: 'finished', is_editable: false } });

        expect(wrapper.find('[data-test="day-menu"]').exists()).toBe(false);
    });

    it('EX-008 a session that can no longer change offers no swap: the suggestion\'s Swap is off and opens nothing', async () => {
        const wrapper = await show({ payload: { ...workout('warning'), status: 'finished', is_editable: false } });

        const swap = wrapper.get('[data-test="swap-suggestion"] button');
        expect(swap.attributes('disabled')).toBeDefined();
        await swap.trigger('click');
        await flushPromises();

        expect(dialog(wrapper)).toBeNull();
        expect(wrapper.find('[data-test="exercise-menu"] button').attributes('disabled')).toBeDefined();
    });

    it('FB-001 a due feedback that is not answered opens its sheet by itself', async () => {
        const wrapper = await show({ payload: workout('warning') });

        expect(dialog(wrapper)).toBe('Chest');
    });

    it('FB-001 no sheet opens by itself when nothing is due', async () => {
        const wrapper = await show();

        expect(dialog(wrapper)).toBeNull();
    });

    it('FB-001 no sheet opens by itself for an answer already waiting in the outbox', async () => {
        vi.mocked(pendingInput).mockImplementation(((name: string) => (name === 'workout_feedback_answer' ? { workout_id: '01JB8W3Q000000000000000901', muscle_group: 'chest' } : undefined)) as never);
        const wrapper = await show({ payload: workout('warning') });

        expect(dialog(wrapper)).toBeNull();
    });

    it('FB-001 cancelling the sheet keeps it closed for the visit: closing another sheet does not bring it back', async () => {
        const wrapper = await show({ payload: workout('warning') });

        await wrapper.get('[data-cancel]').trigger('click');
        expect(dialog(wrapper)).toBeNull();
        await wrapper.get('[data-test="help"]').trigger('click');
        await wrapper.get('[data-cancel]').trigger('click');
        await flushPromises();

        expect(dialog(wrapper)).toBeNull();
        expect(writeMock).not.toHaveBeenCalled();
    });

    it('FB-001 a cancelled muscle does not block the next muscle that becomes due', async () => {
        const payload = reactive(workout('warning'));
        payload.feedback.push({ ...payload.feedback[0]!, muscle_group: 'back', is_due: false });
        const wrapper = await show({ payload });
        expect(dialog(wrapper)).toBe('Chest');

        await wrapper.get('[data-cancel]').trigger('click');
        payload.feedback[1]!.is_due = true;
        await flushPromises();

        expect(dialog(wrapper)).toBe('Back');
    });

    it('FB-001 an answer for one muscle waiting in the outbox does not block the next muscle that is due', async () => {
        vi.mocked(pendingInput).mockImplementation(((name: string, match: (input: unknown) => boolean) =>
            name === 'workout_feedback_answer' && match({ workout_id: '01JB8W3Q000000000000000901', muscle_group: 'chest' }) ? {} : undefined) as never);
        const payload = workout('warning');
        payload.feedback.push({ ...payload.feedback[0]!, muscle_group: 'back' });
        const wrapper = await show({ payload });

        expect(dialog(wrapper)).toBe('Back');
    });

    it('FB-001 feedback that becomes due while another sheet is open opens when that sheet closes', async () => {
        const payload = reactive(workout());
        const wrapper = await show({ payload });
        await setRow(wrapper, BENCH_SET_1).get('[data-test="rir-info"]').trigger('click');
        const open = dialog(wrapper);
        expect(open).not.toBeNull();

        payload.feedback[0]!.is_due = true;
        await flushPromises();
        expect(dialog(wrapper)).toBe(open);
        await wrapper.get('[data-cancel]').trigger('click');
        await flushPromises();

        expect(dialog(wrapper)).toBe('Chest');
    });

    it('FB-001 tapping the feedback banner opens the first due feedback again', async () => {
        const wrapper = await show({ payload: workout('warning') });
        await wrapper.get('[data-cancel]').trigger('click');

        await wrapper.get('[data-test="feedback-banner"]').trigger('click');

        expect(dialog(wrapper)).toBe('Chest');
    });

    it('FB-001 answering the sheet sends workout_feedback_answer through the outbox', async () => {
        const wrapper = await show({ payload: workout('warning') });

        for (const group of wrapper.findAll('[role="radiogroup"]')) await group.get('[role="radio"]').trigger('click');
        await wrapper.get('[data-save]').trigger('click');
        await flushPromises();

        const [name, input] = sent()[0]!;
        expect(name).toBe('workout_feedback_answer');
        expect(input).toMatchObject({ workout_id: '01JB8W3Q000000000000000901', muscle_group: 'chest', pump: 'low' });
        expect(dialog(wrapper)).toBeNull();
    });

    describe('offline, with the sets waiting to sync (OFF-020, OFF-021)', () => {
        const BENCH_SETS = ['01JB8W3Q000000000000000100', '01JB8W3Q000000000000000101', '01JB8W3Q000000000000000102'];
        const ROW_SETS = ['01JB8W3Q000000000000000110', '01JB8W3Q000000000000000111', '01JB8W3Q000000000000000112'];

        /** The newest waiting set_log, set_skip and feedback answer of each set or muscle, as the outbox holds them. */
        function waitingSets(logged: string[], skipped: string[] = [], answered: string[] = [], isLogged = true, isSkipped = true): void {
            vi.mocked(pendingInput).mockImplementation(((name: string, match: (input: unknown) => boolean) => {
                const inputs: unknown[] =
                    name === 'workout_set_log'
                        ? logged.map((set_id) => ({ set_id, weight: { value: 60, unit: 'kg' }, reps: 10, is_logged: isLogged }))
                        : name === 'workout_set_skip'
                          ? skipped.map((set_id) => ({ set_id, is_skipped: isSkipped }))
                          : name === 'workout_feedback_answer'
                            ? answered.map((muscle_group) => ({ workout_id: '01JB8W3Q000000000000000901', muscle_group }))
                            : [];
                return inputs.filter(match).at(-1);
            }) as never);
        }

        it('OFF-021 FB-001 the feedback opens by itself once every set of its exercise is logged and waiting', async () => {
            waitingSets(BENCH_SETS);
            const wrapper = await show();

            expect(dialog(wrapper)).toBe('Chest');
        });

        it('OFF-021 FB-001 a set skipped and waiting counts as done', async () => {
            waitingSets(BENCH_SETS.slice(0, 2), [BENCH_SETS[2]!]);
            const wrapper = await show();

            expect(dialog(wrapper)).toBe('Chest');
        });

        it('OFF-021 FB-001 a set still to do, or logged and then unlogged, keeps the feedback closed', async () => {
            waitingSets(BENCH_SETS.slice(0, 2));
            expect(dialog(await show())).toBeNull();

            const unlogged = workout();
            unlogged.exercises[0]!.sets[2]!.is_logged = true;
            vi.mocked(pendingInput).mockImplementation(((name: string, match: (input: unknown) => boolean) =>
                name === 'workout_set_log'
                    ? [...BENCH_SETS.slice(0, 2).map((set_id) => ({ set_id, is_logged: true })), { set_id: BENCH_SETS[2], is_logged: false }].filter(match).at(-1)
                    : undefined) as never);
            expect(dialog(await show({ payload: unlogged }))).toBeNull();
        });

        it('OFF-021 FB-001 sets the server already holds as logged or skipped count with the one still waiting', async () => {
            const read = workout();
            read.exercises[0]!.sets[0]!.is_logged = true;
            read.exercises[0]!.sets[1]!.is_skipped = true;
            waitingSets([BENCH_SETS[2]!]);
            const wrapper = await show({ payload: read });

            expect(dialog(wrapper)).toBe('Chest');
        });

        it('OFF-021 FB-001 a waiting unlog or unskip closes feedback the server read as due', async () => {
            const logged = workout('warning');
            waitingSets([BENCH_SETS[2]!], [], [], false);
            expect(dialog(await show({ payload: logged }))).toBeNull();

            const skipped = workout('warning');
            Object.assign(skipped.exercises[0]!.sets[2]!, { is_logged: false, is_skipped: true });
            waitingSets([], [BENCH_SETS[2]!], [], true, false);
            expect(dialog(await show({ payload: skipped }))).toBeNull();
        });

        it('OFF-021 FB-001 the feedback banner opens a muscle whose waiting sets are all done', async () => {
            const read = workout();
            read.feedback_banner = { text: 'Your feedback is required', tone: 'warning' };
            waitingSets(BENCH_SETS);
            const wrapper = await show({ payload: read });
            await wrapper.get('[data-cancel]').trigger('click');

            await wrapper.get('[data-test="feedback-banner"]').trigger('click');

            expect(dialog(wrapper)).toBe('Chest');
        });

        it('OFF-009 FB-009 an answer waiting to sync shows on its own muscle\'s feedback button as answered', async () => {
            waitingSets([...BENCH_SETS, ...ROW_SETS], [], ['chest']);
            const wrapper = await show();
            await wrapper.get('[data-cancel]').trigger('click');

            const button = exercise(wrapper, BENCH).get('[data-test="feedback"]');
            expect(button.text()).toBe('Chest feedback');
            expect(button.classes()).toContain('text-accent');
            expect(exercise(wrapper, ROW).get('[data-test="feedback"]').text()).toBe('Back feedback needed');
        });

        it('OFF-009 FB-001 the feedback banner passes over a muscle whose answer waits to sync', async () => {
            const read = workout('warning');
            read.feedback.push({ ...read.feedback[0]!, muscle_group: 'back', title: 'Back', due_after_exercise_id: ROW });
            waitingSets(ROW_SETS, [], ['chest']);
            const wrapper = await show({ payload: read });
            await wrapper.get('[data-cancel]').trigger('click');

            await wrapper.get('[data-test="feedback-banner"]').trigger('click');

            expect(dialog(wrapper)).toBe('Back');
        });

        it('OFF-021 FB-009 the feedback button shows under the exercise whose waiting sets are all done', async () => {
            waitingSets(BENCH_SETS);
            const wrapper = await show();
            await wrapper.get('[data-cancel]').trigger('click');

            expect(exercise(wrapper, BENCH).get('[data-test="feedback"]').text()).toBe('Chest feedback needed');
            expect(exercise(wrapper, ROW).find('[data-test="feedback"]').exists()).toBe(false);
        });
    });

    it('FB-009 a feedback button sits under the exercise the feedback is due after: needed while unanswered', async () => {
        const wrapper = await show({ payload: workout('warning') });
        await wrapper.get('[data-cancel]').trigger('click');

        const button = exercise(wrapper, BENCH).get('[data-test="feedback"]');
        expect(button.text()).toBe('Chest feedback needed');
        expect(exercise(wrapper, ROW).find('[data-test="feedback"]').exists()).toBe(false);
        await button.trigger('click');

        expect(dialog(wrapper)).toBe('Chest');
    });

    it('FB-009 an answered feedback that can still change reads "Chest feedback"; one that is not due shows no button', async () => {
        const answered = workout('warning');
        answered.feedback[0]!.is_answered = true;
        answered.feedback[0]!.is_due = false;
        const wrapper = await show({ payload: answered });

        expect(exercise(wrapper, BENCH).get('[data-test="feedback"]').text()).toBe('Chest feedback');

        const closed = workout('warning');
        closed.feedback[0]!.is_due = false;
        const other = await show({ payload: closed });
        expect(other.find('[data-test="feedback"]').exists()).toBe(false);

        const locked = workout('warning');
        Object.assign(locked.feedback[0]!, { is_answered: true, is_due: false, can_edit: false });
        const frozen = await show({ payload: locked });
        expect(frozen.find('[data-test="feedback"]').exists()).toBe(false);
    });

    it('WO-001 a finished session is read only: no menus, fields or Finish', async () => {
        const wrapper = await show({ payload: { ...workout('warning'), status: 'finished', is_editable: false } });

        expect(wrapper.find('[data-test="finish"]').exists()).toBe(false);
        expect(exercise(wrapper, BENCH).get('[data-test="menu"]').attributes('disabled')).toBeDefined();
        expect(setRow(wrapper, BENCH_SET_1).get('[data-test="weight"]').attributes('disabled')).toBeDefined();
        expect(setRow(wrapper, BENCH_SET_1).get('[data-test="log"]').attributes('disabled')).toBeDefined();
    });

    it('PWA-030 holds the wake lock while a session under way is on show, and lets it go on leaving', async () => {
        readMock.mockResolvedValue(workout('warning'));
        const wrapper = await show({ mesocycleId: '01JB8W3Q000000000000000900', week: 3, day: 1 });

        expect(holdWakeLock).toHaveBeenCalled();
        wrapper.unmount();

        expect(leaveWakeLock).toHaveBeenCalled();
    });

    it('PWA-030 a session not started or finished holds no wake lock; a finished one lets it go', async () => {
        readMock.mockResolvedValueOnce(workout());
        await show({ mesocycleId: 'm', week: 3, day: 1 });
        expect(holdWakeLock).not.toHaveBeenCalled();

        readMock.mockResolvedValueOnce({ ...workout('warning'), status: 'finished', active_bar_text: null });
        await show({ mesocycleId: 'm', week: 3, day: 1 });
        expect(holdWakeLock).not.toHaveBeenCalled();
        expect(releaseWakeLock).toHaveBeenCalled();
    });

    it('PWA-030 a session started offline holds the wake lock while its start waits to sync (OFF-021)', async () => {
        vi.mocked(pendingInput).mockImplementation(((name: string) => (name === 'workout_start' ? { workout_id: '01JB8W3Q000000000000000901' } : undefined)) as never);
        readMock.mockResolvedValueOnce(workout());
        await show({ mesocycleId: 'm', week: 3, day: 1 });

        expect(holdWakeLock).toHaveBeenCalledWith('01JB8W3Q000000000000000901');
    });

    it('PWA-030 a session finished offline lets the wake lock go while its finish waits to sync, and takes it no more', async () => {
        vi.mocked(pendingInput).mockImplementation(((name: string) => (name === 'workout_finish' ? { workout_id: '01JB8W3Q000000000000000901' } : undefined)) as never);
        readMock.mockResolvedValueOnce(workout('warning'));
        await show({ mesocycleId: 'm', week: 3, day: 1 });

        expect(holdWakeLock).not.toHaveBeenCalled();
        expect(releaseWakeLock).toHaveBeenCalledWith('01JB8W3Q000000000000000901');
    });

    it('PWA-030 a finished session lets go only its own wake lock, by its id', async () => {
        readMock.mockResolvedValueOnce({ ...workout('warning'), workout_id: 'earlier', status: 'finished', active_bar_text: null });
        await show({ mesocycleId: 'm', week: 2, day: 1 });

        expect(releaseWakeLock).toHaveBeenCalledWith('earlier');
        expect(releaseWakeLock).not.toHaveBeenCalledWith();
    });

    it('PWA-030 a finish sent before the read returns keeps the lock let go and Finish off', async () => {
        readMock.mockResolvedValue({ ...workout('warning'), can_finish: true });
        const wrapper = await show({ mesocycleId: 'm', week: 3, day: 1 });
        expect(holdWakeLock).toHaveBeenCalledTimes(1);

        await wrapper.get('[data-test="finish"]').trigger('click');
        await flushPromises();

        expect(releaseWakeLock).toHaveBeenCalledWith('01JB8W3Q000000000000000901');
        expect(holdWakeLock).toHaveBeenCalledTimes(1);
        expect(wrapper.get('[data-test="finish"]').attributes('disabled')).toBeDefined();
    });

    it('CAP-014 a double tap on Finish sends one finish', async () => {
        let stored!: () => void;
        writeMock.mockImplementation(() => new Promise((resolve) => (stored = () => resolve({} as never))));
        const wrapper = await show({ payload: { ...workout('warning'), can_finish: true } });

        await wrapper.get('[data-test="finish"]').trigger('click');
        await wrapper.get('[data-test="finish"]').trigger('click');
        stored();
        await flushPromises();

        expect(sent().map(([name]) => name)).toEqual(['workout_finish']);
    });

    it('OFF-006 a finish that could not be stored leaves Finish on', async () => {
        writeMock.mockRejectedValue(new CapabilityError('workout_finish', 401, null));
        const wrapper = await show({ payload: { ...workout('warning'), can_finish: true } });

        await wrapper.get('[data-test="finish"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-test="finish"]').attributes('disabled')).toBeUndefined();
    });

    it('OFF-021 Finish is on while changes wait to sync, as workout_finish has the last word', async () => {
        const wrapper = await show({ payload: workout('warning'), pending: true });

        expect(wrapper.get('[data-test="finish"]').attributes('disabled')).toBeUndefined();
    });

    it('OFF-021 Finish is off while a finish for this session waits to sync, so it is not sent twice', async () => {
        vi.mocked(pendingInput).mockImplementation(((name: string) => (name === 'workout_finish' ? { workout_id: '01JB8W3Q000000000000000901' } : undefined)) as never);
        const wrapper = await show({ payload: { ...workout('warning'), can_finish: true }, pending: true });

        expect(wrapper.get('[data-test="finish"]').attributes('disabled')).toBeDefined();
    });

    it('PWA-035 holds app updates for the length of a session under way, and not before or after', async () => {
        const holding = () => (vi.mocked(useHoldUpdates).mock.calls.at(-1)![0] as () => boolean)();

        readMock.mockResolvedValueOnce(workout('warning'));
        await show({ mesocycleId: 'm', week: 3, day: 1 });
        expect(holding()).toBe(true);

        readMock.mockResolvedValueOnce(workout());
        await show({ mesocycleId: 'm', week: 3, day: 1 });
        expect(holding()).toBe(false);

        readMock.mockResolvedValueOnce({ ...workout('warning'), status: 'finished', active_bar_text: null });
        await show({ mesocycleId: 'm', week: 3, day: 1 });
        expect(holding()).toBe(false);

        await show({ payload: workout('warning') });
        expect(holding()).toBe(false);
    });

    it('OFF-011 a refused finish turns Finish back on and takes the wake lock again', async () => {
        readMock.mockResolvedValue({ ...workout('warning'), can_finish: true });
        const wrapper = await show({ mesocycleId: 'm', week: 3, day: 1 });

        await wrapper.get('[data-test="finish"]').trigger('click');
        await flushPromises();
        expect(wrapper.get('[data-test="finish"]').attributes('disabled')).toBeDefined();
        const holds = vi.mocked(holdWakeLock).mock.calls.length;

        tray.value = [{ seq: 1, name: 'workout_finish', input: { workout_id: '01JB8W3Q000000000000000901' }, refusal: { status: 422, body: {} } }];
        await flushPromises();

        expect(wrapper.get('[data-test="finish"]').attributes('disabled')).toBeUndefined();
        expect(vi.mocked(holdWakeLock).mock.calls.length).toBeGreaterThan(holds);
        expect(holdWakeLock).toHaveBeenLastCalledWith('01JB8W3Q000000000000000901');
    });

    it('OFF-011 a refused finish of another workout leaves this one\'s Finish as it is', async () => {
        readMock.mockResolvedValue({ ...workout('warning'), can_finish: true });
        const wrapper = await show({ mesocycleId: 'm', week: 3, day: 1 });

        await wrapper.get('[data-test="finish"]').trigger('click');
        await flushPromises();
        tray.value = [{ seq: 1, name: 'workout_finish', input: { workout_id: 'another' }, refusal: { status: 422, body: {} } }];
        await flushPromises();

        expect(wrapper.get('[data-test="finish"]').attributes('disabled')).toBeDefined();
    });

    it('WO-010 a start that synced before the next read is not sent again', async () => {
        const wrapper = await show();

        await setRow(wrapper, BENCH_SET_1).get('[data-test="log"]').trigger('click');
        await flushPromises();
        await setRow(wrapper, '01JB8W3Q000000000000000101').get('[data-test="log"]').trigger('click');
        await flushPromises();

        expect(sent().map(([name]) => name)).toEqual(['workout_start', 'workout_set_log', 'workout_set_log']);
    });

    it('WO-010 a refused start is sent again with the next change', async () => {
        const wrapper = await show();

        await setRow(wrapper, BENCH_SET_1).get('[data-test="log"]').trigger('click');
        await flushPromises();
        tray.value = [{ seq: 1, name: 'workout_start', input: { workout_id: '01JB8W3Q000000000000000901' }, refusal: { status: 422, body: {} } }];
        await flushPromises();
        await setRow(wrapper, '01JB8W3Q000000000000000101').get('[data-test="log"]').trigger('click');
        await flushPromises();

        expect(sent().map(([name]) => name)).toEqual(['workout_start', 'workout_set_log', 'workout_start', 'workout_set_log']);
    });

    it('PWA-030 a session covered by another screen leaves the wake lock, and takes it again on coming back', async () => {
        readMock.mockResolvedValue(workout('warning'));
        const top = ref(true);
        const Framed = defineComponent({
            setup() {
                provideScreenTop(top);
                return () => h(WorkoutView, { mesocycleId: 'm', week: 3, day: 1 });
            },
        });
        router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:any(.*)*', component: { template: '<p />' } }] });
        await router.push('/workouts/m/3/1');
        mount(Framed, { attachTo: document.body, global: { plugins: [router], stubs: { teleport: true } } });
        await flushPromises();
        expect(holdWakeLock).toHaveBeenCalledTimes(1);

        top.value = false;
        await flushPromises();
        expect(leaveWakeLock).toHaveBeenCalledWith('01JB8W3Q000000000000000901');
        expect(holdWakeLock).toHaveBeenCalledTimes(1);

        top.value = true;
        await flushPromises();
        expect(holdWakeLock).toHaveBeenCalledTimes(2);
        expect(holdWakeLock).toHaveBeenLastCalledWith('01JB8W3Q000000000000000901');
    });

    it('NAV-004 a session under way puts its bar text up, and finishing it takes it down', async () => {
        readMock.mockResolvedValueOnce(workout('warning'));
        await show({ mesocycleId: '01JB8W3Q000000000000000900', week: 3, day: 1 });

        expect(activeWorkout.value).toMatchObject({ path: '/workouts/01JB8W3Q000000000000000900/3/1', text: 'Upper A · 1 of 2 exercises' });

        readMock.mockResolvedValueOnce({ ...workout('warning'), status: 'finished', active_bar_text: null });
        await show({ mesocycleId: '01JB8W3Q000000000000000900', week: 3, day: 1 });

        expect(activeWorkout.value).toBeNull();
    });

    it('NAV-004 a /dev/screens fixture leaves the bar and the wake lock alone', async () => {
        await show({ payload: workout('warning') });

        expect(activeWorkout.value).toBeNull();
        expect(holdWakeLock).not.toHaveBeenCalled();
    });

    it('UI-020 every button and field on the screen is at least the 48 px tap size, and nothing listens for a swipe', async () => {
        const wrapper = await show();

        for (const el of wrapper.findAll('[data-set] button, [data-set] input, [data-exercise] header a, [data-test="finish"]')) {
            expect(el.classes().some((name) => /^(min-h-tap|h-tap)$/.test(name)), el.html()).toBe(true);
        }
        expect(wrapper.html()).not.toMatch(/touchstart|touchmove|swipe/i);
    });
});
