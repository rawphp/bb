import 'fake-indexeddb/auto';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, ref } from 'vue';
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
        expect(wrapper.find('[data-test="look-back"]').exists()).toBe(false);
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

    it('WO-020 the set menu adds a set below, of its own type, under a new id (CAP-019)', async () => {
        const wrapper = await show({ payload: workout('warning') });

        await menu(wrapper, `[data-set="${BENCH_SET_1}"]`, 'add');

        const [name, input] = sent()[0]!;
        expect(name).toBe('workout_set_add');
        expect(input).toMatchObject({ workout_exercise_id: BENCH, after_set_id: BENCH_SET_1, set_type: 'regular_straight' });
        expect(writeMock.mock.calls[0]![2].creates).toEqual([input.set_id]);
    });

    it('WO-020 the set menu skips a set, and unskips a skipped one', async () => {
        const payload = workout('warning');
        payload.exercises[1]!.sets[2]!.is_skipped = true;
        const wrapper = await show({ payload });

        await menu(wrapper, `[data-set="${BENCH_SET_1}"]`, 'skip');
        const skipped = payload.exercises[1]!.sets[2]!.id;
        expect(setRow(wrapper, skipped).classes()).toContain('opacity-50');
        expect(setRow(wrapper, skipped).get('[data-test="weight"]').attributes('disabled')).toBeDefined();
        await menu(wrapper, `[data-set="${skipped}"]`, 'skip');

        expect(sent()).toEqual([
            ['workout_set_skip', { set_id: BENCH_SET_1, is_skipped: true }],
            ['workout_set_skip', { set_id: skipped, is_skipped: false }],
        ]);
    });

    it('WO-020 Delete set asks first, then deletes it', async () => {
        const wrapper = await show({ payload: workout('warning') });

        await menu(wrapper, `[data-set="${BENCH_SET_1}"]`, 'delete');
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
        const types = wrapper.findAll(`[data-set="${BENCH_SET_1}"] [data-test^="item-type:"]`);
        expect(types.map((item) => item.find('span').text())).toEqual(workout().set_type_choices.map((choice) => `Set type: ${choice.label}`));
        expect(wrapper.get(`[data-set="${BENCH_SET_1}"] [data-test="item-type:regular_straight"]`).attributes('disabled')).toBeDefined();
        await wrapper.get(`[data-set="${BENCH_SET_1}"] [data-test="item-type:myorep"]`).trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['workout_set_type_set', { set_id: BENCH_SET_1, set_type: 'myorep' }]]);
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

    it('WO-008 the calendar lists the earlier sessions and opens the one picked', async () => {
        const wrapper = await show();

        await wrapper.get('[data-test="look-back"] [data-test="menu"]').trigger('click');
        const items = wrapper.findAll('[data-test="look-back"] [role="menuitem"]');
        expect(items).toHaveLength(workout().look_back.length);
        expect(items[0]!.text()).toBe('Week 2 · Day 2 · Lower ADone');
        await items[0]!.trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.fullPath).toBe('/workouts/01JB8W3Q000000000000000900/2/2');
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

        tray.value = [{ seq: 1, name: 'workout_finish', input: { workout_id: '01JB8W3Q000000000000000901' }, refusal: { status: 422, body: {} } }];
        await flushPromises();

        expect(wrapper.get('[data-test="finish"]').attributes('disabled')).toBeUndefined();
        expect(holdWakeLock).toHaveBeenCalledTimes(2);
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
