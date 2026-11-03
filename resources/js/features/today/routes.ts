import type { RouteRecordRaw } from 'vue-router';

// Today's day-balance sheets at their own routes (DAY-001, DAY-002, DAY-020, SCH-036), so
// /dev/screens/<capability> finds each one with its fixture (meta.screen) and a link can open
// one for a day. Opened at its route, a sheet goes back to Today when it closes or saves,
// without the server's toast: the Today screen draws the sheets over itself and listens to
// @close and @saved, and shows the toast (UI-010).
const DATE = '(\\d{4}-\\d{2}-\\d{2})';

// The Today screen (SCH-001 to SCH-023): the server's today at the tab's root, another day
// at /today/<date>. It shows two reads, so /dev/screens/today_show hands it the week strip's
// fixture too (meta.fixtures). First, so /dev/screens/today_show opens the screen.
const todayMeta = { screen: 'today_show', fixtures: { week: 'week_show' }, tab: 'today' } as const;
const today = [
    { path: '/today', name: 'today', component: () => import('./TodayView.vue'), meta: todayMeta },
    { path: `/today/:date${DATE}`, name: 'today-day', component: () => import('./TodayView.vue'), props: true, meta: todayMeta },
] satisfies RouteRecordRaw[];

const daySheets = [
    { path: `/today/:date${DATE}/adjust`, component: () => import('./sheets/AdjustSheet.vue'), props: true, meta: { screen: 'day_adjust_show', tab: 'today' } },
    { path: `/today/:date${DATE}/redistribute`, component: () => import('./sheets/RedistributeSheet.vue'), props: true, meta: { screen: 'day_redistribute_show', tab: 'today' } },
    { path: `/today/:date${DATE}/optimize`, component: () => import('./sheets/TodayOptimizeSheet.vue'), props: true, meta: { screen: 'day_optimize_show', tab: 'today' } },
    // Reads nothing (meta.fixture false): /dev/screens/off_plan_log opens it on the clock's today.
    { path: `/today/:date${DATE}/off-plan`, component: () => import('./sheets/OffPlanSheet.vue'), props: true, meta: { screen: 'off_plan_log', fixture: false, tab: 'today' } },
] satisfies RouteRecordRaw[];

// Today's sheets: schedule (SCH-023, SCH-030 to SCH-039, SCH-050, SCH-051), away week
// (SCH-060) and missed sessions and weeks (SCH-057, SCH-058). Today opens them over its
// timeline; until it does, and for screenshots, each opens on its own here, in local and
// testing only: /dev/screens/<fixture>?view=<name> renders it from a fixture, and its path
// reads the server's day.
const sheet = (path: string, name: string, component: RouteRecordRaw['component'], screen = 'today_show', fixture = true): RouteRecordRaw =>
    ({ path: `/dev/today/${path}`, name, component, meta: { dev: true, shell: false, screen, fixture } }) as RouteRecordRaw;

// Dev routes stay out of a production build (CAP-004), as features/dev's do.
const scheduleSheets = (import.meta.env.DEV
    ? [
          sheet('calendar', 'today-calendar', () => import('./sheets/TodayCalendarSheet.vue'), 'week_show'),
          sheet('workout-time', 'today-workout-time', () => import('./sheets/TodayWorkoutTimeSheet.vue')),
          sheet('add-meal', 'today-add-meal', () => import('./sheets/TodayAddMealSheet.vue')),
          sheet('move-day', 'today-move-day', () => import('./sheets/TodayMoveDaySheet.vue')),
          sheet('add-workout', 'add-workout', () => import('./sheets/AddWorkoutSheet.vue')),
          sheet('busy-period', 'busy-period', () => import('./sheets/BusyPeriodSheet.vue')),
          sheet('edit-schedule', 'edit-schedule', () => import('./sheets/EditScheduleSheet.vue')),
          sheet('weekday-change', 'weekday-change', () => import('./sheets/WeekdayChangeSheet.vue'), 'training_weekdays_change', false),
          sheet('away-week', 'away-week', () => import('./sheets/AwayWeekSheet.vue'), 'away_week_show'),
          sheet('missed-weeks', 'today-missed-weeks', () => import('./sheets/TodayMissedWeeksSheet.vue')),
          sheet('missed-day', 'today-missed-day', () => import('./sheets/TodayMissedDaySheet.vue')),
      ]
    : []) satisfies RouteRecordRaw[];

export default [...today, ...daySheets, ...scheduleSheets] satisfies RouteRecordRaw[];
