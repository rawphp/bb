<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { write } from '@/app/call';
import { writeFailureMessage } from '@/app/errors';
import { entries, isPending } from '@/app/outbox';
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';
import { useWrite, type WriteOutcome } from '@/app/useWrite';
import type { MealCheckInInput } from '@/contracts/meal_check_in';
import type { MealCheckInUndoInput } from '@/contracts/meal_check_in_undo';
import type { MealDeleteInput } from '@/contracts/meal_delete';
import type { MealPlanningDoneInput } from '@/contracts/meal_planning_done';
import type { MesocycleActiveRestTakeInput } from '@/contracts/mesocycle_active_rest_take';
import type { OtherActivityDeleteInput } from '@/contracts/other_activity_delete';
import type { TodayShowOutput } from '@/contracts/today_show';
import type { WeekCopyInput } from '@/contracts/week_copy';
import type { WeekShowOutput } from '@/contracts/week_show';
import ActionMenu from '@/design/ActionMenu.vue';
import BottomSheet from '@/design/BottomSheet.vue';
import ItemCard from '@/design/ItemCard.vue';
import StatusPill from '@/design/StatusPill.vue';
import { toneClasses } from '@/design/tones';
import { toneIcons } from '@/design/toneIcons';
import WeekStrip from '@/design/WeekStrip.vue';
import CopySheet from '@/features/copy/CopySheet.vue';
import ManualCheckInSheet from './ManualCheckInSheet.vue';
import NextMesocycleCard from './NextMesocycleCard.vue';
import AddWorkoutSheet from './sheets/AddWorkoutSheet.vue';
import BusyPeriodSheet from './sheets/BusyPeriodSheet.vue';
import EditScheduleSheet from './sheets/EditScheduleSheet.vue';
import OffPlanRow from './sheets/OffPlanRow.vue';
import TodayAddMealSheet from './sheets/TodayAddMealSheet.vue';
import TodayCalendarSheet from './sheets/TodayCalendarSheet.vue';
import TodayMissedDaySheet from './sheets/TodayMissedDaySheet.vue';
import TodayMissedWeeksSheet from './sheets/TodayMissedWeeksSheet.vue';
import TodayMoveDaySheet from './sheets/TodayMoveDaySheet.vue';
import TodayTourOverlay from './sheets/TodayTourOverlay.vue';
import TodayWorkoutTimeSheet from './sheets/TodayWorkoutTimeSheet.vue';
import TodayBusyCard from './TodayBusyCard.vue';
import TodayMealCard from './TodayMealCard.vue';
import TodayNowLine from './TodayNowLine.vue';
import TodayOtherActivityCard from './TodayOtherActivityCard.vue';
import TodayStepRow from './TodayStepRow.vue';
import TodayTotalsBar from './TodayTotalsBar.vue';
import TodayWeighInRow from './TodayWeighInRow.vue';
import TodayWorkoutCard from './TodayWorkoutCard.vue';
import WeekWarnings from './WeekWarnings.vue';

type Meal = TodayShowOutput['meals'][number];
type MealAction = Meal['menu'][number]['action'];
type Next = NonNullable<TodayShowOutput['next_mesocycle']>;

// The home screen (SCH-001 to SCH-023): the week strip over the day's timeline, the day's
// totals and steps, the next-mesocycle prompt, and the sheets Today opens over itself.
// It reads the day (today_show) and its week (week_show); `date` names the day, else the
// server's today. Picking another day opens /today/<date> in place of this one, which
// reads that day. The server sends every word, number and tone (CAP-001); this script
// holds which sheet or menu is open, the condensed view and whether the tour ran
// (VIEW-002). Ported from rp-clone's TodayView.
const props = withDefaults(defineProps<{ payload?: TodayShowOutput; week?: WeekShowOutput; date?: string; pending?: boolean }>(), { pending: false });

const router = useRouter();
const input = props.date ? { date: props.date } : {};
const { data, failure, load } = useRead<TodayShowOutput>('today_show', input, 'Today could not be loaded.', props.payload);
const { data: weekData, failure: weekFailure, load: loadWeek } = useRead<WeekShowOutput>('week_show', input, 'This week could not be loaded.', props.week);
// A write re-reads both: what changes a day's target changes its calories on the strip too.
const loadBoth = (options?: { fresh?: boolean }) => Promise.all([load(options), loadWeek(options).catch(() => undefined)]).then(() => undefined);
const { send, busy, error } = useWrite(loadBoth);

// OFF-008: the server's numbers while a write that affects them waits in the outbox.
const pending = computed(() => props.pending || isPending('today_show'));
const weekPending = computed(() => props.pending || isPending('week_show'));

function reload(): void {
    void loadBoth().catch(() => undefined);
}

// SCH-012: back in the foreground (the installed app resumed, the tab shown again), the day
// is read again, so the Now line and what is next follow the clock. A fixture never reads.
function resumed(): void {
    if (document.visibilityState === 'visible' && !props.payload) reload();
}
onMounted(() => document.addEventListener('visibilitychange', resumed));
onUnmounted(() => document.removeEventListener('visibilitychange', resumed));

// ONB-002: with no plan yet, setup is still open, so Today sends the user back to it. A
// cached copy can predate setup finishing (on the Coach, or another device), so only the
// server's own answer sends them.
async function backToSetup(): Promise<void> {
    try {
        await load({ fresh: true });
    } catch {
        return;
    }
    if (data.value?.setup_needed) void router.replace('/onboarding');
}

watch(
    () => data.value?.setup_needed,
    (needed) => {
        if (needed) void backToSetup();
    },
    { immediate: true },
);

// Device settings, kept like the appearance: the condensed view (SCH-007) and the tour,
// which runs once (ONB-030).
const CONDENSED_KEY = 'bb.today.condensed';
const TOUR_KEY = 'bb.today.tourSeen';
const condensed = ref(localStorage.getItem(CONDENSED_KEY) === '1');
const touring = ref(localStorage.getItem(TOUR_KEY) !== '1');

function toggleCondensed(): void {
    condensed.value = !condensed.value;
    localStorage.setItem(CONDENSED_KEY, condensed.value ? '1' : '0');
}

function endTour(): void {
    touring.value = false;
    localStorage.setItem(TOUR_KEY, '1');
}

// The timeline in the server's order (SCH-001), each entry with the item it names.
type Entry = { key: string } & (
    | { kind: 'meal'; item: Meal }
    | { kind: 'workout'; item: TodayShowOutput['workouts'][number] }
    | { kind: 'weigh_in'; item: TodayShowOutput['weigh_ins'][number] }
    | { kind: 'other_activity'; item: TodayShowOutput['other_activities'][number] }
    | { kind: 'busy'; item: TodayShowOutput['busy_periods'][number] }
);

const timeline = computed<Entry[]>(() => {
    const day = data.value;
    if (!day) return [];
    const lists = { meal: day.meals, workout: day.workouts, weigh_in: day.weigh_ins, other_activity: day.other_activities, busy: day.busy_periods };
    return day.timeline.flatMap(({ kind, id }) => {
        const item = (lists[kind] as { id: string }[]).find((candidate) => candidate.id === id);
        return item ? [{ kind, key: `${kind}:${id}`, item } as Entry] : [];
    });
});

// SCH-012: the Now line sits before the entry the server names, or after the last one.
const nowBefore = computed(() => {
    const before = data.value?.now?.before;
    return before ? `${before.kind}:${before.id}` : null;
});

// SCH-012: opened on today with the user still at the top, the screen scrolls a Now line that
// sits below the fold into view with the item after it, so what is next shows first, as a
// calendar's day view does. As little as that takes, once per screen, never after the user
// has scrolled, and not under the tour.
const root = ref<HTMLElement | null>(null);
const FOLD_MARGIN_PX = 96;
let nowBrought = false;

watch(
    () => data.value?.now,
    async (now) => {
        if (nowBrought || !now || touring.value) return;
        nowBrought = true;
        await nextTick();
        const line = root.value?.querySelector<HTMLElement>('[data-test="now-line"]');
        const screen = root.value?.closest<HTMLElement>('.bb-screen');
        if (!line || !screen || screen.scrollTop > 0) return;
        if (line.getBoundingClientRect().bottom <= screen.getBoundingClientRect().bottom - FOLD_MARGIN_PX) return;
        const next = line.nextElementSibling instanceof HTMLElement && line.nextElementSibling.matches('[data-timeline]') ? line.nextElementSibling : line;
        next.scrollIntoView?.({ block: 'nearest' });
    },
    { immediate: true, flush: 'post' },
);

// OFF-009: the user's own check-in or its removal, newest first, while it waits to sync.
function pendingCheck(meal: Meal): boolean | null {
    const entry = entries.value
        .filter((item) => item.refusal === undefined && (item.name === 'meal_check_in' || item.name === 'meal_check_in_undo') && item.input.meal_id === meal.id)
        .at(-1);
    return entry ? entry.name === 'meal_check_in' : null;
}

function go(date: string | null | undefined): void {
    if (date) void router.replace(`/today/${date}`);
}

function open(path: string): void {
    void router.push(path);
}

// Sheets Today draws over itself. Each is mounted while open (v-if), so it starts fresh.
type Sheet =
    | { kind: 'calendar' | 'add-meal' | 'add-workout' | 'busy-period' | 'edit-schedule' }
    | { kind: 'time' | 'move'; workoutId: string }
    | { kind: 'copy'; mealId?: string; sourceDate?: string }
    | { kind: 'manual'; meal: Meal };
const sheet = ref<Sheet | null>(null);

function closeSheet(saved = false): void {
    sheet.value = null;
    if (saved) reload();
}

function pick(date: string): void {
    sheet.value = null;
    go(date);
}

// A menu raised by a long press (SCH-010, MEAL-009), and a question asked before a delete or copy.
type Menu = { title: string; items: { key: string; label: string; enabled: boolean; destructive?: boolean }[]; run: (key: string) => void };
const menu = ref<Menu | null>(null);
type Confirm = { title: string; message: string; action: string; run: () => Promise<WriteOutcome | null> };
const confirm = ref<Confirm | null>(null);

function choose(key: string): void {
    const chosen = menu.value;
    menu.value = null;
    chosen?.run(key);
}

async function confirmed(): Promise<void> {
    const asked = confirm.value;
    if (!asked) return;
    // A refusal stays on the sheet with the server's words; only a done write closes it.
    if ((await asked.run()) === 'done') confirm.value = null;
}

// SCH-009, MEAL-040: a tap on the box checks the meal in, or removes its check-in, through
// the outbox, so it works offline (OFF-030). MEAL-045: a meal the server blocks says why.
const notice = ref<{ mealId: string; text: string } | null>(null);
const manualError = ref<string | null>(null);

// Meals whose write is still being stored: until it is in the outbox, pendingCheck cannot
// see it, so a second tap would be decided from the old state. It waits instead.
const storing = ref(new Set<string>());

// The two writes the box sends through the outbox, each with its own input.
type CheckInWrite = { name: 'meal_check_in'; body: MealCheckInInput } | { name: 'meal_check_in_undo'; body: MealCheckInUndoInput };

async function queue(meal: Meal, { name, body }: CheckInWrite, label: string): Promise<boolean> {
    if (storing.value.has(meal.id)) return false;
    storing.value.add(meal.id);
    try {
        await write(name, { ...body }, { label });
        return true;
    } catch (failure) {
        notice.value = { mealId: meal.id, text: writeFailureMessage(failure, 'The check-in could not be saved.') };
        return false;
    } finally {
        storing.value.delete(meal.id);
    }
}

/** Whether the meal shows as checked in: the user's own waiting write first (OFF-009), else the server's state. */
function checkedIn(meal: Meal): boolean {
    return pendingCheck(meal) ?? meal.check_in.state !== 'not_checked_in';
}

// Check in meal and Remove check-in say which way they go; a meal already that way is left alone.
function checkIn(meal: Meal): void {
    notice.value = null;
    if (checkedIn(meal)) return;
    if (meal.check_in.blocked_message) notice.value = { mealId: meal.id, text: meal.check_in.blocked_message };
    else void queue(meal, { name: 'meal_check_in', body: { meal_id: meal.id } }, `Checked in ${meal.name}`);
}

function removeCheckIn(meal: Meal): void {
    notice.value = null;
    if (!checkedIn(meal)) return;
    void queue(meal, { name: 'meal_check_in_undo', body: { meal_id: meal.id } }, `Removed the check-in of ${meal.name}`);
}

function toggle(meal: Meal): void {
    if (checkedIn(meal)) removeCheckIn(meal);
    else checkIn(meal);
}

async function saveManual(meal: Meal, body: MealCheckInInput): Promise<void> {
    manualError.value = null;
    if (await queue(meal, { name: 'meal_check_in', body }, `Checked in ${meal.name} at manual macros`)) {
        sheet.value = null;
    } else if (notice.value?.mealId === meal.id) {
        // The sheet says why, not the card under it.
        manualError.value = notice.value.text;
        notice.value = null;
    }
}

function askDelete(meal: Meal): void {
    confirm.value = {
        title: `Delete ${meal.name}?`,
        message: 'Its foods and check-in go with it.',
        action: 'Delete meal',
        run: () => send('meal_delete', { meal_id: meal.id } satisfies MealDeleteInput, 'The meal could not be deleted.'),
    };
}

// The meal screen carries out these, opened with the action raised (MEAL-070).
const ON_MEAL_SCREEN: MealAction[] = ['edit_macros_time', 'load_saved_meal', 'save_as_saved_meal', 'remove_foods'];

function mealAction(meal: Meal, action: MealAction): void {
    if (ON_MEAL_SCREEN.includes(action)) return open(`/meals/${meal.id}?action=${action}`);
    switch (action) {
        case 'check_in_meal':
            return checkIn(meal);
        case 'remove_check_in':
            return removeCheckIn(meal);
        case 'check_in_manual':
            // MEAL-045: a manual check-in is a check-in, so a blocked meal says why instead.
            notice.value = meal.check_in.blocked_message ? { mealId: meal.id, text: meal.check_in.blocked_message } : null;
            if (notice.value) return;
            manualError.value = null;
            sheet.value = { kind: 'manual', meal };
            return;
        case 'still_planning':
        case 'done_planning':
            void send('meal_planning_done', { meal_id: meal.id, done: action === 'done_planning' } satisfies MealPlanningDoneInput, 'The meal could not be changed.');
            return;
        case 'copy_foods':
            sheet.value = { kind: 'copy', mealId: meal.id };
            return;
        case 'delete_meal':
            return askDelete(meal);
    }
}

function mealMenu(meal: Meal): void {
    menu.value = {
        title: meal.name,
        items: meal.menu.map((item) => ({ key: item.action, label: item.label, enabled: item.enabled, destructive: item.action === 'delete_meal' })),
        run: (key) => mealAction(meal, key as MealAction),
    };
}

function workoutMenu(workout: TodayShowOutput['workouts'][number]): void {
    menu.value = {
        title: workout.name,
        items: workout.menu.map((item) => ({ key: item.action, label: item.label, enabled: true })),
        run: (key) => (sheet.value = { kind: key === 'move_day' ? 'move' : 'time', workoutId: workout.id }),
    };
}

function activityMenu(activity: TodayShowOutput['other_activities'][number]): void {
    menu.value = {
        title: activity.name,
        // Edit opens the Add workout sheet, which edits the day's one activity (add_workout.activity);
        // with none offered (a past day, NUT-024) there is nothing for it to edit.
        items: activity.menu.map((item) => ({
            key: item.action,
            label: item.label,
            enabled: item.action !== 'edit' || data.value?.add_workout?.activity?.id === activity.id,
            destructive: item.action === 'delete',
        })),
        run: (key) => {
            if (key === 'edit') sheet.value = { kind: 'add-workout' };
            else if (key === 'delete') void send('other_activity_delete', { id: activity.id } satisfies OtherActivityDeleteInput, 'The activity could not be deleted.');
        },
    };
}

// SCH-030: the schedule menu, as the server lists it for the day.
const scheduleItems = computed(() => data.value?.schedule_menu.map((item) => ({ key: item.action, title: item.label })) ?? []);

function scheduleAction(key: string): void {
    const day = data.value!;
    switch (key) {
        case 'add_meal':
        case 'add_workout':
        case 'add_busy_period':
        case 'edit_schedule':
            sheet.value = { kind: ({ add_meal: 'add-meal', add_workout: 'add-workout', add_busy_period: 'busy-period', edit_schedule: 'edit-schedule' } as const)[key] };
            return;
        case 'copy_day':
            sheet.value = { kind: 'copy', sourceDate: day.date };
            return;
        case 'copy_week': {
            const weekStart = weekData.value?.week_start;
            if (!weekStart) return;
            confirm.value = {
                title: "Copy this week's foods to next week?",
                message: "Each meal's foods replace the foods of the same meal next week.",
                action: 'Copy week',
                run: () => send('week_copy', { week_start: weekStart } satisfies WeekCopyInput, 'The week could not be copied.'),
            };
            return;
        }
        case 'optimize_schedule':
            return open(`/today/${day.date}/optimize`);
    }
}

// MESO-060: the create flow opens on the route picked; Copy starts from this mesocycle.
function chooseNext(next: Next, route: Next['options'][number]['route']): void {
    open(route === 'copy_previous' ? `/train/mesocycles/build?route=${route}&copy=${next.mesocycle_id}` : `/train/mesocycles/build?route=${route}`);
}

function takeActiveRest(next: Next): void {
    void send('mesocycle_active_rest_take', { mesocycle_id: next.mesocycle_id } satisfies MesocycleActiveRestTakeInput, 'The active rest could not be taken.');
}

// SCH-057, SCH-058: asked once the tour is done, one at a time; answering reads the day again.
const missedClosed = ref(false);
const missedWeeksClosed = ref(false);
const askMissed = computed(() => !touring.value && !missedClosed.value && !!data.value?.missed_session);
const askMissedWeeks = computed(() => !touring.value && !askMissed.value && !missedWeeksClosed.value && !!data.value?.missed_weeks);

function closeMissed(which: 'day' | 'weeks'): void {
    if (which === 'day') missedClosed.value = true;
    else missedWeeksClosed.value = true;
    reload();
}

const headerButton = 'flex min-h-tap min-w-tap items-center justify-center text-accent disabled:text-secondary-text';
</script>

<template>
    <main ref="root" class="bb-screen-content">
        <div class="mb-3 flex items-center gap-1 px-1">
            <h1 class="flex-1 text-3xl font-bold">Today</h1>
            <ActionMenu v-if="data" label="Schedule menu" :items="scheduleItems" @select="scheduleAction">
                <svg viewBox="0 0 24 24" class="size-6 text-accent" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" />
                    <path d="M8 12h.01M12 12h.01M16 12h.01" stroke-width="2.5" stroke-linecap="round" />
                </svg>
            </ActionMenu>
        </div>

        <div class="flex flex-col gap-2.5">
            <template v-if="data">
                <!-- ONB-031 and the server's other notices for the day. -->
                <p v-for="banner in data.banners" :key="banner.text" class="flex items-center gap-2 rounded-xl p-3 text-sm font-semibold" :class="toneClasses[banner.tone]" data-test="banner">
                    <svg viewBox="0 0 24 24" class="size-4 shrink-0" aria-hidden="true"><path :d="toneIcons[banner.tone]" fill="currentColor" fill-rule="evenodd" /></svg>
                    <span>{{ banner.text }}</span>
                </p>
                <!-- REV-001. -->
                <ItemCard v-if="data.review_available" data-test="review" title="Weekly review available" :status="{ kind: 'button', title: 'Open' }" @action="open('/review')">
                    <template #icon>
                        <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4 6.5l1.5 1.5L8 5.5M4 12.5l1.5 1.5L8 11.5M4 18.5l1.5 1.5L8 17.5M11 7h9M11 13h9M11 19h9" />
                        </svg>
                    </template>
                </ItemCard>
            </template>

            <div class="flex items-center gap-1 px-1">
                <div class="min-w-0 flex-1">
                    <div v-if="weekData && (weekData.heading || weekData.pills.length)" class="flex flex-wrap items-center gap-1.5" data-test="week-pills">
                        <span v-if="weekData.heading" class="text-xs font-semibold text-secondary-text">{{ weekData.heading }}</span>
                        <StatusPill v-for="pill in weekData.pills" :key="pill.text" :text="pill.text" :tone="pill.tone" />
                    </div>
                    <h2 v-if="data" class="text-lg font-semibold text-primary-text">{{ data.title }}</h2>
                </div>
                <button type="button" :class="headerButton" aria-label="Open calendar" :disabled="!weekData?.calendar" @click="sheet = { kind: 'calendar' }">
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <rect x="4" y="5" width="16" height="15" rx="2" />
                        <path d="M4 9h16M9 3v4M15 3v4" stroke-linecap="round" />
                    </svg>
                </button>
                <button type="button" :class="headerButton" aria-label="Condensed view" :aria-pressed="condensed" @click="toggleCondensed">
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true">
                        <path v-if="condensed" d="M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01" />
                        <path v-else d="M4 4h16v7H4zM4 15h16v5H4z" />
                    </svg>
                </button>
            </div>

            <WeekStrip
                :days="weekData?.days ?? []"
                label="Days of the week"
                :loading="!weekData"
                :error="weekFailure && !weekData ? weekFailure.error : null"
                :pending="weekPending"
                @select="go"
                @swipe="(step) => go(step > 0 ? weekData?.next_week : weekData?.previous_week)"
            />
            <WeekWarnings :warnings="weekData?.warnings ?? []" />

            <template v-if="data">
                <NextMesocycleCard
                    v-if="data.next_mesocycle"
                    :next="data.next_mesocycle"
                    :busy="busy"
                    @choose="(route) => chooseNext(data!.next_mesocycle!, route)"
                    @take-active-rest="takeActiveRest(data.next_mesocycle)"
                />
                <TodayTotalsBar
                    v-if="data.totals"
                    :totals="data.totals"
                    :pending="pending"
                    @redistribute="open(`/today/${data.date}/redistribute`)"
                    @save-food="open(`/today/${data.date}/off-plan`)"
                    @adjust="open(`/today/${data.date}/adjust`)"
                />
                <TodayStepRow v-if="data.steps" :steps="data.steps" />

                <p v-if="error" role="alert" class="rounded-xl p-3 text-sm" :class="toneClasses.warning">{{ error }}</p>

                <template v-for="entry in timeline" :key="entry.key">
                    <TodayNowLine v-if="data.now && nowBefore === entry.key" :now="data.now" />
                    <div data-timeline :data-kind="entry.kind">
                        <TodayWeighInRow v-if="entry.kind === 'weigh_in'" :weigh-in="entry.item" @add="open('/weigh-ins/log')" />
                        <template v-else-if="entry.kind === 'meal'">
                            <TodayMealCard
                                :meal="entry.item"
                                :condensed="condensed"
                                :pending="pending"
                                :pending-check="pendingCheck(entry.item)"
                                @toggle="toggle(entry.item)"
                                @open="open(`/meals/${entry.item.id}`)"
                                @menu="mealMenu(entry.item)"
                                @edit="open(`/meals/${entry.item.id}?action=edit_macros_time`)"
                                @delete="askDelete(entry.item)"
                            />
                            <p v-if="notice?.mealId === entry.item.id" role="alert" class="mt-1.5 px-1 text-sm text-tone-warning">{{ notice.text }}</p>
                        </template>
                        <TodayWorkoutCard
                            v-else-if="entry.kind === 'workout'"
                            :workout="entry.item"
                            :condensed="condensed"
                            :pending="pending"
                            @start="open(`/workouts/${entry.item.mesocycle_id}/${entry.item.week}/${entry.item.day}`)"
                            @menu="workoutMenu(entry.item)"
                        />
                        <TodayOtherActivityCard v-else-if="entry.kind === 'other_activity'" :activity="entry.item" @menu="activityMenu(entry.item)" />
                        <TodayBusyCard v-else :busy="entry.item" />
                    </div>
                </template>
                <TodayNowLine v-if="data.now && !nowBefore" :now="data.now" />
                <!-- DAY-001: food eaten off-plan has no meal slot; it sits under the day's items. -->
                <OffPlanRow v-for="entry in data.off_plan" :key="entry.id" :entry="entry" :pending="pending" />
            </template>
            <ReadState v-else :failure="failure" what="Today" @retry="reload" />
        </div>

        <template v-if="data">
            <TodayCalendarSheet v-if="sheet?.kind === 'calendar'" :payload="weekData" :date="data.date" @pick="pick" @close="closeSheet()" />
            <TodayAddMealSheet v-else-if="sheet?.kind === 'add-meal'" :payload="data" :pending="pending" @close="closeSheet" />
            <AddWorkoutSheet v-else-if="sheet?.kind === 'add-workout'" :payload="data" :pending="pending" @close="closeSheet" />
            <BusyPeriodSheet v-else-if="sheet?.kind === 'busy-period'" :payload="data" :pending="pending" @close="closeSheet" />
            <EditScheduleSheet v-else-if="sheet?.kind === 'edit-schedule'" :payload="data" :pending="pending" @close="closeSheet" />
            <TodayWorkoutTimeSheet v-else-if="sheet?.kind === 'time'" :payload="data" :workout-id="sheet.workoutId" @close="closeSheet" />
            <TodayMoveDaySheet v-else-if="sheet?.kind === 'move'" :payload="data" :workout-id="sheet.workoutId" @close="closeSheet" />
            <CopySheet v-else-if="sheet?.kind === 'copy'" :meal-id="sheet.mealId" :source-date="sheet.sourceDate" @close="closeSheet(true)" />
            <ManualCheckInSheet
                v-else-if="sheet?.kind === 'manual'"
                :meal="sheet.meal"
                :busy="storing.has(sheet.meal.id)"
                :error="manualError"
                @cancel="closeSheet()"
                @save="(body) => saveManual((sheet as { meal: Meal }).meal, body)"
            />

            <BottomSheet v-if="menu" :open="true" :title="menu.title" :save-title="null" :heights="['half', 'full']" @cancel="menu = null">
                <div class="flex flex-col divide-y divide-secondary-text/15 p-4">
                    <button
                        v-for="item in menu.items"
                        :key="item.key"
                        type="button"
                        class="min-h-tap px-1 text-left disabled:text-secondary-text"
                        :class="item.destructive ? 'text-tone-warning' : 'text-primary-text'"
                        :disabled="!item.enabled"
                        :data-test="`menu-${item.key}`"
                        @click="choose(item.key)"
                    >
                        {{ item.label }}
                    </button>
                </div>
            </BottomSheet>
            <!-- The wrapper names the sheet for tests; the sheet itself goes to <body>. -->
            <div v-if="confirm" data-test="confirm">
                <BottomSheet
                    :open="true"
                    :title="confirm.title"
                    :save-title="confirm.action"
                    :save-disabled="busy"
                    :cancel-disabled="busy"
                    :dismissible="!busy"
                    :error="error"
                    :heights="['half']"
                    @cancel="confirm = null"
                    @save="confirmed"
                >
                    <p class="p-4 text-primary-text">{{ confirm.message }}</p>
                </BottomSheet>
            </div>

            <TodayMissedDaySheet v-if="askMissed" :payload="data" @close="closeMissed('day')" />
            <TodayMissedWeeksSheet v-else-if="askMissedWeeks" :payload="data" @close="closeMissed('weeks')" />
            <TodayTourOverlay v-if="touring && !data?.setup_needed" @done="endTour" />
        </template>
    </main>
</template>
