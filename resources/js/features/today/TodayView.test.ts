import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import { CapabilityError, read, write, writeNow } from '@/app/call';
import { entries, isPending } from '@/app/outbox';
import type { TodayShowOutput } from '@/contracts/today_show';
import type { WeekShowOutput } from '@/contracts/week_show';
import WeekStrip from '@/design/WeekStrip.vue';
import { inputViolations, screenFixture } from '@/testing/schemas';
import CopySheet from '@/features/copy/CopySheet.vue';
import ManualCheckInSheet from './ManualCheckInSheet.vue';
import AddWorkoutSheet from './sheets/AddWorkoutSheet.vue';
import BusyPeriodSheet from './sheets/BusyPeriodSheet.vue';
import EditScheduleSheet from './sheets/EditScheduleSheet.vue';
import TodayAddMealSheet from './sheets/TodayAddMealSheet.vue';
import TodayCalendarSheet from './sheets/TodayCalendarSheet.vue';
import TodayMissedDaySheet from './sheets/TodayMissedDaySheet.vue';
import TodayMissedWeeksSheet from './sheets/TodayMissedWeeksSheet.vue';
import TodayMoveDaySheet from './sheets/TodayMoveDaySheet.vue';
import TodayWorkoutTimeSheet from './sheets/TodayWorkoutTimeSheet.vue';
import TodayView from './TodayView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), write: vi.fn(), writeNow: vi.fn() }));
vi.mock('@/app/outbox', async (original) => ({
    ...(await original<typeof import('@/app/outbox')>()),
    isPending: vi.fn(() => false),
    // Plain, so a test can put an entry in before it mounts the screen.
    entries: { value: [] },
}));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(write);
const writeNowMock = vi.mocked(writeNow);
const pendingEntries = entries as unknown as { value: unknown[] };

const today = (state?: string) => screenFixture<TodayShowOutput>(state ? `today_show.${state}` : 'today_show');
const week = (state?: string) => screenFixture<WeekShowOutput>(state ? `week_show.${state}` : 'week_show');
// The fixture's missed session and missed weeks open their sheets on load; most tests look at the day itself.
const day = (state?: string): TodayShowOutput => ({ ...today(state), missed_session: null, missed_weeks: null });

const LUNCH = '01JBX0M1N2P3Q4R5S6T7V8W9X1';
const BREAKFAST = '01JBX0M1N2P3Q4R5S6T7V8W9X0';
const PRE_WORKOUT = '01JBX0M1N2P3Q4R5S6T7V8W9X2';

/** An outbox entry for a meal's check-in or its removal, waiting unless refused. */
const entry = (seq: number, name: string, mealId: string, refused = false) => ({
    seq,
    name,
    input: { meal_id: mealId },
    affects: ['today_show'],
    ...(refused ? { refusal: { status: 422, body: {} } } : {}),
});

/** The day with one meal's menu item turned on or off, as the server would send it. */
function withMenu(mealId: string, action: string, enabled: boolean): TodayShowOutput {
    const payload = day();
    const found = payload.meals.find((m) => m.id === mealId)!;
    const item = found.menu.find((i) => i.action === action);
    if (item) item.enabled = enabled;
    else found.menu.push({ action: action as never, label: action === 'done_planning' ? 'Done planning foods' : action, enabled });
    return payload;
}

async function menuItem(wrapper: VueWrapper, mealId: string, action: string) {
    await meal(wrapper, mealId).get('[data-test="meal-card"]').trigger('contextmenu');
    await wrapper.get(`[data-test="menu-${action}"]`).trigger('click');
    await flushPromises();
}

let router: Router;

async function show(props: Record<string, unknown> = { payload: day(), week: week() }): Promise<VueWrapper> {
    router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:any(.*)*', component: { template: '<p />' } }] });
    await router.push('/today');
    const wrapper = mount(TodayView, { props, attachTo: document.body, global: { plugins: [router], stubs: { teleport: true } } });
    await flushPromises();
    return wrapper;
}

function path(): string {
    return router.currentRoute.value.fullPath;
}

/** Each [name, input] sent through the outbox or at once, checked against that write's schema snapshot. */
function sent(): [string, Record<string, unknown>][] {
    return [...writeMock.mock.calls, ...writeNowMock.mock.calls].map(([name, input]) => {
        expect(inputViolations(name, input), name).toEqual([]);
        return [name, input as Record<string, unknown>];
    });
}

function meal(wrapper: VueWrapper, id: string) {
    return wrapper.get(`[data-meal="${id}"]`);
}

describe('TodayView', () => {
    beforeEach(() => {
        localStorage.clear();
        // The tour has been seen, unless a test says otherwise.
        localStorage.setItem('bb.today.tourSeen', '1');
        readMock.mockReset().mockReturnValue(new Promise(() => {}));
        writeMock.mockReset().mockResolvedValue({} as never);
        writeNowMock.mockReset().mockResolvedValue({});
        vi.mocked(isPending).mockReturnValue(false);
        pendingEntries.value = [];
        document.body.innerHTML = '';
    });

    it("SCH-001 lists the day's items in time order: weigh-in, meals, workouts, other activities and busy periods", async () => {
        const wrapper = await show();

        const items = wrapper.findAll('[data-timeline] h3').map((title) => title.text());
        expect(items).toEqual(['Weigh-in', 'Breakfast', 'Team meeting', 'Lunch', 'Pre-workout', 'Week 2 · Day 2 · Upper A', 'Tennis', 'Post-workout']);
        expect(wrapper.text()).toContain('Office cake');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('SCH-006 shows the day totals; Adjust opens DAY-020 for the day, the wand DAY-002 and + DAY-001', async () => {
        const wrapper = await show();

        expect(wrapper.text()).toContain('780/2600');
        await wrapper.get('[data-test="adjust"]').trigger('click');
        await flushPromises();
        expect(path()).toBe('/today/2026-09-23/adjust');

        await router.push('/today');
        await wrapper.get('[aria-label="Redistribute"]').trigger('click');
        await flushPromises();
        expect(path()).toBe('/today/2026-09-23/redistribute');

        await router.push('/today');
        await wrapper.get('[aria-label="Save food macros"]').trigger('click');
        await flushPromises();
        expect(path()).toBe('/today/2026-09-23/off-plan');
    });

    it("SCH-011 shows the day's step target with the actual steps", async () => {
        const wrapper = await show();

        expect(wrapper.get('[data-test="steps"]').text()).toBe('Step count target 6-8k · 5,432 steps');
    });

    it("SCH-020 shows the week strip with each day's calories, and a tap opens that day", async () => {
        const wrapper = await show();

        const strip = wrapper.getComponent(WeekStrip);
        expect(strip.findAll('[data-calories]').map((c) => c.text())).toEqual(['2200', '2200', '2400', '2200', '2600', '2200', '2400']);
        expect(wrapper.text()).toContain('WEEK 2');
        expect(wrapper.text()).toContain('Wednesday, Sep 23');

        await strip.findAll('button')[5]!.trigger('click');
        await flushPromises();
        expect(path()).toBe('/today/2026-09-24');
    });

    it('SCH-020 a swipe on the strip changes the week', async () => {
        const wrapper = await show();

        wrapper.getComponent(WeekStrip).vm.$emit('swipe', 1);
        await flushPromises();
        expect(path()).toBe('/today/2026-09-26');

        wrapper.getComponent(WeekStrip).vm.$emit('swipe', -1);
        await flushPromises();
        expect(path()).toBe('/today/2026-09-12');
    });

    it('SCH-022 shows Deload above the strip in a deload week', async () => {
        const wrapper = await show({ payload: day(), week: week('warning') });

        expect(wrapper.get('[data-test="week-pills"]').text()).toContain('Deload');
        expect(wrapper.get('[data-test="week-pills"]').text()).toContain('Away');
    });

    it('SCH-023 the calendar icon opens the month view, and Go opens the date picked', async () => {
        const wrapper = await show();

        await wrapper.get('[aria-label="Open calendar"]').trigger('click');
        const calendar = wrapper.getComponent(TodayCalendarSheet);
        expect(calendar.props('payload')).toEqual(week());
        calendar.vm.$emit('pick', '2026-10-02');
        await flushPromises();

        expect(path()).toBe('/today/2026-10-02');
        expect(wrapper.findComponent(TodayCalendarSheet).exists()).toBe(false);
    });

    it('SCH-007 the condensed view collapses the cards, and the setting persists', async () => {
        let wrapper = await show();
        await wrapper.get('[aria-label="Condensed view"]').trigger('click');

        expect(wrapper.get('[aria-label="Condensed view"]').attributes('aria-pressed')).toBe('true');
        expect(meal(wrapper, BREAKFAST).text()).toContain('8:00 AM · 780 kcal');
        wrapper.unmount();

        wrapper = await show();
        expect(meal(wrapper, BREAKFAST).text()).toContain('8:00 AM · 780 kcal');
        expect(meal(wrapper, BREAKFAST).find('[data-test="macros"]').exists()).toBe(false);
    });

    it('SCH-009 one tap on the box checks a meal in through the outbox (OFF-030)', async () => {
        const wrapper = await show();

        await meal(wrapper, LUNCH).get('[role="checkbox"]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['meal_check_in', { meal_id: LUNCH }]]);
        expect(writeMock.mock.calls[0]![2]).toEqual({ label: 'Checked in Lunch' });
    });

    it('MEAL-040 a tap on a checked meal removes its check-in', async () => {
        const wrapper = await show();

        await meal(wrapper, BREAKFAST).get('[role="checkbox"]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['meal_check_in_undo', { meal_id: BREAKFAST }]]);
    });

    it('MEAL-045 a meal the server blocks says why, and is not checked in', async () => {
        const wrapper = await show({ payload: day('warning'), week: week() });

        await meal(wrapper, LUNCH).get('[role="checkbox"]').trigger('click');
        await flushPromises();

        expect(writeMock).not.toHaveBeenCalled();
        expect(wrapper.get('[role="alert"]').text()).toBe("Fix this meal's foods or mark it done planning before you check it in.");
    });

    it('OFF-009 shows a check-in waiting in the outbox as ticked on its meal', async () => {
        pendingEntries.value = [{ seq: 1, name: 'meal_check_in', input: { meal_id: LUNCH }, affects: ['today_show'] }];
        const wrapper = await show();

        expect(meal(wrapper, LUNCH).get('[role="checkbox"]').attributes('aria-checked')).toBe('true');
        expect(meal(wrapper, LUNCH).text()).toContain('Waiting to sync');
    });

    it('OFF-009 a check-in waiting for one meal leaves the others as the server says', async () => {
        pendingEntries.value = [entry(1, 'meal_check_in', LUNCH)];
        const wrapper = await show();

        expect(meal(wrapper, PRE_WORKOUT).get('[role="checkbox"]').attributes('aria-checked')).toBe('false');
        await meal(wrapper, PRE_WORKOUT).get('[role="checkbox"]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['meal_check_in', { meal_id: PRE_WORKOUT }]]);
    });

    it('MEAL-040 a tap on a meal whose check-in waits to sync removes it', async () => {
        pendingEntries.value = [entry(1, 'meal_check_in', LUNCH)];
        const wrapper = await show();

        await meal(wrapper, LUNCH).get('[role="checkbox"]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['meal_check_in_undo', { meal_id: LUNCH }]]);
    });

    it('OFF-009 the newest waiting entry wins: a check-in then its removal shows unticked', async () => {
        pendingEntries.value = [entry(1, 'meal_check_in', LUNCH), entry(2, 'meal_check_in_undo', LUNCH)];
        const wrapper = await show();

        expect(meal(wrapper, LUNCH).get('[role="checkbox"]').attributes('aria-checked')).toBe('false');
        await meal(wrapper, LUNCH).get('[role="checkbox"]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['meal_check_in', { meal_id: LUNCH }]]);
    });

    it('OFF-011 a refused check-in is not shown as made, and the box follows the server', async () => {
        pendingEntries.value = [entry(1, 'meal_check_in', LUNCH, true)];
        const wrapper = await show();

        expect(meal(wrapper, LUNCH).get('[role="checkbox"]').attributes('aria-checked')).toBe('false');
        await meal(wrapper, LUNCH).get('[role="checkbox"]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['meal_check_in', { meal_id: LUNCH }]]);
    });

    it('MEAL-047 a tap on a meal checked in under target removes the check-in', async () => {
        const payload = day();
        payload.meals[1]!.check_in.state = 'under';
        const wrapper = await show({ payload, week: week() });

        await meal(wrapper, LUNCH).get('[role="checkbox"]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['meal_check_in_undo', { meal_id: LUNCH }]]);
    });

    it('CAP-014 a second tap while the first check-in is still being stored sends nothing more', async () => {
        let store: (value: never) => void = () => {};
        writeMock.mockReturnValueOnce(new Promise((resolve) => (store = resolve)));
        const wrapper = await show();

        await meal(wrapper, LUNCH).get('[role="checkbox"]').trigger('click');
        await meal(wrapper, LUNCH).get('[role="checkbox"]').trigger('click');
        store({} as never);
        await flushPromises();

        expect(sent()).toEqual([['meal_check_in', { meal_id: LUNCH }]]);
    });

    it('OFF-006 says why a check-in could not be stored, under its meal', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('meal_check_in', 401, null));
        const wrapper = await show();

        await meal(wrapper, LUNCH).get('[role="checkbox"]').trigger('click');
        await flushPromises();

        expect(meal(wrapper, LUNCH).element.parentElement!.querySelector('[role="alert"]')?.textContent).toBeTruthy();
    });

    it('MEAL-009 Check in meal from the menu checks in, and never removes a check-in that waits to sync', async () => {
        let wrapper = await show({ payload: withMenu(LUNCH, 'check_in_meal', true), week: week() });
        await menuItem(wrapper, LUNCH, 'check_in_meal');
        expect(sent()).toEqual([['meal_check_in', { meal_id: LUNCH }]]);
        wrapper.unmount();

        writeMock.mockClear();
        pendingEntries.value = [entry(1, 'meal_check_in', LUNCH)];
        wrapper = await show({ payload: withMenu(LUNCH, 'check_in_meal', true), week: week() });
        await menuItem(wrapper, LUNCH, 'check_in_meal');
        expect(sent()).toEqual([]);
    });

    it('MEAL-009 Remove check-in from the menu removes it, and never checks in a meal whose removal waits', async () => {
        let wrapper = await show();
        await menuItem(wrapper, BREAKFAST, 'remove_check_in');
        expect(sent()).toEqual([['meal_check_in_undo', { meal_id: BREAKFAST }]]);
        wrapper.unmount();

        writeMock.mockClear();
        pendingEntries.value = [entry(1, 'meal_check_in_undo', BREAKFAST)];
        wrapper = await show();
        await menuItem(wrapper, BREAKFAST, 'remove_check_in');
        expect(sent()).toEqual([]);
    });

    it('MEAL-009 Done planning foods marks the meal planned', async () => {
        const wrapper = await show({ payload: withMenu(LUNCH, 'done_planning', true), week: week() });

        await menuItem(wrapper, LUNCH, 'done_planning');

        expect(sent()).toEqual([['meal_planning_done', { meal_id: LUNCH, done: true }]]);
    });

    it('MEAL-060 Copy foods opens the copy sheet on that meal', async () => {
        const wrapper = await show();

        await menuItem(wrapper, LUNCH, 'copy_foods');

        expect(wrapper.getComponent(CopySheet).props('mealId')).toBe(LUNCH);
    });

    it('MEAL-041 says why a manual check-in could not be stored, and keeps the sheet open', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('meal_check_in', 401, null));
        const wrapper = await show();
        await menuItem(wrapper, LUNCH, 'check_in_manual');

        wrapper.getComponent(ManualCheckInSheet).vm.$emit('save', { meal_id: LUNCH, time: '12:45', kcal: 600, protein_g: 40, fat_g: 20, carbs_g: 55 });
        await flushPromises();

        expect(wrapper.getComponent(ManualCheckInSheet).props('error')).toBeTruthy();
    });

    it('SCH-010 a long press opens the meal menu with the server\'s items, off where it says so', async () => {
        const wrapper = await show();

        await meal(wrapper, LUNCH).get('[data-test="meal-card"]').trigger('contextmenu');

        const items = wrapper.findAll('[data-test^="menu-"]');
        expect(items.map((item) => item.text())).toEqual(today().meals[1]!.menu.map((item) => item.label));
        expect(wrapper.get('[data-test="menu-check_in_meal"]').attributes('disabled')).toBeDefined();
    });

    it('MEAL-041 Check in at manual macros opens the manual check-in, which saves through the outbox', async () => {
        const wrapper = await show();
        await meal(wrapper, LUNCH).get('[data-test="meal-card"]').trigger('contextmenu');
        await wrapper.get('[data-test="menu-check_in_manual"]').trigger('click');

        const sheet = wrapper.getComponent(ManualCheckInSheet);
        sheet.vm.$emit('save', { meal_id: LUNCH, time: '12:45', kcal: 600, protein_g: 40, fat_g: 20, carbs_g: 55 });
        await flushPromises();

        expect(sent()).toEqual([['meal_check_in', { meal_id: LUNCH, time: '12:45', kcal: 600, protein_g: 40, fat_g: 20, carbs_g: 55 }]]);
        expect(wrapper.findComponent(ManualCheckInSheet).exists()).toBe(false);
    });

    it('MEAL-009 Still planning foods sends meal_planning_done for that meal', async () => {
        const wrapper = await show();
        await meal(wrapper, LUNCH).get('[data-test="meal-card"]').trigger('contextmenu');
        await wrapper.get('[data-test="menu-still_planning"]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['meal_planning_done', { meal_id: LUNCH, done: false }]]);
    });

    it('MEAL-009 the meal screen\'s actions open the meal with that action', async () => {
        const wrapper = await show();
        await meal(wrapper, LUNCH).get('[data-test="meal-card"]').trigger('contextmenu');
        await wrapper.get('[data-test="menu-load_saved_meal"]').trigger('click');
        await flushPromises();

        expect(path()).toBe(`/meals/${LUNCH}?action=load_saved_meal`);
    });

    it('SCH-010 Delete from the swipe asks first, then deletes the meal', async () => {
        const wrapper = await show();
        await meal(wrapper, LUNCH).get('[data-test="swipe-delete"]').trigger('click');

        expect(wrapper.text()).toContain('Delete Lunch?');
        expect(writeNowMock).not.toHaveBeenCalled();
        await wrapper.get('[data-test="confirm"] [data-save]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['meal_delete', { meal_id: LUNCH }]]);
    });

    it('SCH-010 Edit macros from the swipe opens the meal to edit its macros and time', async () => {
        const wrapper = await show();
        await meal(wrapper, LUNCH).get('[data-test="swipe-edit"]').trigger('click');
        await flushPromises();

        expect(path()).toBe(`/meals/${LUNCH}?action=edit_macros_time`);
    });

    it('NAV-002 a meal opens the meal screen and Start opens the workout', async () => {
        const wrapper = await show();

        await meal(wrapper, LUNCH).get('[data-test="meal-card"]').trigger('click');
        await flushPromises();
        expect(path()).toBe(`/meals/${LUNCH}`);

        await router.push('/today');
        await wrapper.get('[data-test="workout-card"] button').trigger('click');
        await flushPromises();
        expect(path()).toBe('/workouts/01JBWZK7Q8R9S0T1V2W3X4Y5Z6/2/2');
    });

    it('SCH-050 the workout menu opens the time sheet for that workout', async () => {
        const wrapper = await show();

        await wrapper.get('[data-test="workout-card"]').trigger('contextmenu');
        await wrapper.get('[data-test="menu-change_time"]').trigger('click');

        const sheet = wrapper.getComponent(TodayWorkoutTimeSheet);
        expect(sheet.props('workoutId')).toBe('workout');
        sheet.vm.$emit('close', true);
        await flushPromises();
        expect(wrapper.findComponent(TodayWorkoutTimeSheet).exists()).toBe(false);
    });

    it('SCH-032 an other activity\'s menu deletes it, and the week strip reads again', async () => {
        const wrapper = await show();

        await wrapper.get('[data-test="activity-card"]').trigger('contextmenu');
        await wrapper.get('[data-test="menu-delete"]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['other_activity_delete', { id: '01JBX0A1B2C3D4E5F6G7H8J9K0' }]]);
        expect(readMock).toHaveBeenCalledWith('today_show', {}, expect.any(Function), expect.any(Function));
        expect(readMock).toHaveBeenCalledWith('week_show', {}, expect.any(Function), expect.any(Function));
    });

    it('SCH-032 Edit opens the activity in the Add workout sheet and deletes nothing', async () => {
        const wrapper = await show();

        await wrapper.get('[data-test="activity-card"]').trigger('contextmenu');
        await wrapper.get('[data-test="menu-edit"]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([]);
        expect(wrapper.getComponent(AddWorkoutSheet).props('payload')).toEqual(day());
    });

    it('SCH-051 the workout menu opens the move sheet for that workout', async () => {
        const wrapper = await show();

        await wrapper.get('[data-test="workout-card"]').trigger('contextmenu');
        await wrapper.get('[data-test="menu-move_day"]').trigger('click');

        expect(wrapper.getComponent(TodayMoveDaySheet).props('workoutId')).toBe('workout');
        expect(wrapper.findComponent(TodayWorkoutTimeSheet).exists()).toBe(false);
    });

    it('SCH-004 Add weigh-in opens the weigh-in entry', async () => {
        const payload = { ...day(), weigh_ins: [{ id: 'weigh-in', time_text: '7:00 AM', weight_text: null }] };
        const wrapper = await show({ payload, week: week() });

        await wrapper.get('[data-test="weigh-in"] button').trigger('click');
        await flushPromises();

        expect(path()).toBe('/weigh-ins/log');
    });

    it('SCH-030 the schedule menu lists the server\'s items; Add meal opens its sheet on this day', async () => {
        const wrapper = await show();

        const menu = wrapper.get('[aria-label="Schedule menu"]');
        await menu.trigger('click');
        expect(wrapper.findAll('[role="menuitem"]').map((item) => item.text())).toEqual(today().schedule_menu.map((item) => item.label));
        await wrapper.get('[data-test="item-add_meal"]').trigger('click');

        expect(wrapper.getComponent(TodayAddMealSheet).props('payload')).toEqual(day());
    });

    it.each([
        ['add_workout', AddWorkoutSheet],
        ['add_busy_period', BusyPeriodSheet],
        ['edit_schedule', EditScheduleSheet],
    ] as const)('SCH-030 %s opens its sheet on this day', async (action, component) => {
        const wrapper = await show();

        await wrapper.get('[aria-label="Schedule menu"]').trigger('click');
        await wrapper.get(`[data-test="item-${action}"]`).trigger('click');

        expect(wrapper.getComponent(component as never).props('payload')).toEqual(day());
    });

    it('SCH-034 Copy day opens the copy sheet on this day', async () => {
        const wrapper = await show();

        await wrapper.get('[aria-label="Schedule menu"]').trigger('click');
        await wrapper.get('[data-test="item-copy_day"]').trigger('click');

        expect(wrapper.getComponent(CopySheet).props('sourceDate')).toBe('2026-09-23');
        expect(wrapper.getComponent(CopySheet).props('mealId')).toBeUndefined();
    });

    it('SCH-035 Copy week asks first, then copies the week shown', async () => {
        const wrapper = await show();

        await wrapper.get('[aria-label="Schedule menu"]').trigger('click');
        await wrapper.get('[data-test="item-copy_week"]').trigger('click');
        await wrapper.get('[data-test="confirm"] [data-save]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['week_copy', { week_start: '2026-09-19' }]]);
    });

    it('SCH-036 Optimize schedule opens its preview for the day', async () => {
        const wrapper = await show();

        await wrapper.get('[aria-label="Schedule menu"]').trigger('click');
        await wrapper.get('[data-test="item-optimize_schedule"]').trigger('click');
        await flushPromises();

        expect(path()).toBe('/today/2026-09-23/optimize');
    });

    it('MESO-060 the next mesocycle card opens the create flow the user picks', async () => {
        const wrapper = await show();

        await wrapper.findAll('[data-test="option"]')[0]!.trigger('click');
        await flushPromises();

        expect(path()).toBe('/train/mesocycles/build?route=copy_previous&copy=01JBWZK7Q8R9S0T1V2W3X4Y5Z6');
    });

    it('MESO-060 a template or the builder starts fresh, not from this mesocycle', async () => {
        const wrapper = await show();

        await wrapper.findAll('[data-test="option"]')[1]!.trigger('click');
        await flushPromises();

        expect(path()).toBe('/train/mesocycles/build?route=template');
    });

    it('CAP-012 a refused delete keeps the question open with the server\'s words', async () => {
        writeNowMock.mockRejectedValueOnce(new CapabilityError('meal_delete', 422, { error: { code: 'validation_failed', message: 'This meal is already gone.', violations: [] } }));
        const wrapper = await show();

        await meal(wrapper, LUNCH).get('[data-test="swipe-delete"]').trigger('click');
        await wrapper.get('[data-test="confirm"] [data-save]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-test="confirm"]').text()).toContain('This meal is already gone.');
    });

    it('MESO-064 Take an active rest sends it for the mesocycle', async () => {
        const wrapper = await show();

        await wrapper.get('[data-test="active-rest"]').trigger('click');
        await flushPromises();

        expect(sent()).toEqual([['mesocycle_active_rest_take', { mesocycle_id: '01JBWZK7Q8R9S0T1V2W3X4Y5Z6' }]]);
    });

    it('REV-001 shows the weekly review card when a review is ready', async () => {
        const wrapper = await show();

        await wrapper.get('[data-test="review"] button').trigger('click');
        await flushPromises();

        expect(path()).toBe('/review');
    });

    it('ONB-031 shows the plan-start banner before the plan starts, with no items (today_show.empty)', async () => {
        const wrapper = await show({ payload: today('empty'), week: week('empty') });

        expect(wrapper.get('[data-test="banner"]').text()).toBe('Your plan starts Sat, Sep 19');
        expect(wrapper.findAll('[data-timeline]')).toHaveLength(0);
        expect(wrapper.find('[data-test="review"]').exists()).toBe(false);
        expect(wrapper.getComponent(WeekStrip).findAll('[data-calories]').map((c) => c.text())).toEqual(Array(7).fill('–'));
    });

    it('ONB-030 runs the tour the first time Today opens, and never again once done', async () => {
        localStorage.removeItem('bb.today.tourSeen');
        let wrapper = await show();

        expect(wrapper.get('[role="dialog"] h2').text()).toBe('Days and calories');
        await wrapper.get('[data-test="skip"]').trigger('click');
        expect(wrapper.find('[data-test="skip"]').exists()).toBe(false);
        wrapper.unmount();

        wrapper = await show();
        expect(wrapper.find('[data-test="skip"]').exists()).toBe(false);
    });

    it('SCH-057 asks about a missed session once the tour is done', async () => {
        localStorage.removeItem('bb.today.tourSeen');
        const wrapper = await show({ payload: { ...today(), missed_weeks: null }, week: week() });

        expect(wrapper.findComponent(TodayMissedDaySheet).exists()).toBe(false);
        await wrapper.get('[data-test="skip"]').trigger('click');

        expect(wrapper.getComponent(TodayMissedDaySheet).props('payload')).toEqual({ ...today(), missed_weeks: null });
    });

    it('SCH-058 asks about missed weeks once the missed session is answered, and reads the day after each', async () => {
        const wrapper = await show({ payload: today(), week: week() });

        expect(wrapper.findComponent(TodayMissedDaySheet).exists()).toBe(true);
        expect(wrapper.findComponent(TodayMissedWeeksSheet).exists()).toBe(false);

        wrapper.getComponent(TodayMissedDaySheet).vm.$emit('close');
        await flushPromises();
        expect(readMock).toHaveBeenCalledWith('today_show', {}, expect.any(Function), expect.any(Function));
        expect(wrapper.findComponent(TodayMissedDaySheet).exists()).toBe(false);
        expect(wrapper.getComponent(TodayMissedWeeksSheet).props('payload')).toEqual(today());

        wrapper.getComponent(TodayMissedWeeksSheet).vm.$emit('close');
        await flushPromises();
        expect(wrapper.findComponent(TodayMissedWeeksSheet).exists()).toBe(false);
        expect(wrapper.findComponent(TodayMissedDaySheet).exists()).toBe(false);
    });

    it('CAP-002 reads the day and its week, with skeletons until they come', async () => {
        const wrapper = await show({ date: '2026-09-24' });

        expect(readMock).toHaveBeenCalledWith('today_show', { date: '2026-09-24' }, expect.any(Function), expect.any(Function));
        expect(readMock).toHaveBeenCalledWith('week_show', { date: '2026-09-24' }, expect.any(Function), expect.any(Function));
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        expect(wrapper.getComponent(WeekStrip).findAll('[data-placeholder]')).toHaveLength(7);
    });

    it("CAP-002 reads the server's today when no day is named", async () => {
        await show({});

        expect(readMock).toHaveBeenCalledWith('today_show', {}, expect.any(Function), expect.any(Function));
        expect(readMock).toHaveBeenCalledWith('week_show', {}, expect.any(Function), expect.any(Function));
    });

    it('CAP-004 says when the day could not load', async () => {
        readMock.mockRejectedValue(new CapabilityError('today_show', 500, null));
        const wrapper = await show({});

        expect(wrapper.text()).toContain('Today could not be loaded.');
    });

    it("OFF-008 marks the server's numbers as waiting to sync while a write is pending", async () => {
        const wrapper = await show({ payload: day(), week: week(), pending: true });

        expect(wrapper.get('[data-test="totals"]').text()).toContain('Waiting to sync');
        expect(wrapper.getComponent(WeekStrip).props('pending')).toBe(true);
        expect(meal(wrapper, LUNCH).text()).toContain('Waiting to sync');
    });

    it('OFF-008 marks them pending when an outbox entry affects the day', async () => {
        vi.mocked(isPending).mockImplementation((name) => name === 'today_show' || name === 'week_show');
        const wrapper = await show();

        expect(wrapper.get('[data-test="totals"]').text()).toContain('Waiting to sync');
        expect(wrapper.getComponent(WeekStrip).props('pending')).toBe(true);
    });
});
