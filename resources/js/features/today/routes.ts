import type { RouteRecordRaw } from 'vue-router';

// Today's schedule sheets (SCH-023, SCH-030 to SCH-039, SCH-050, SCH-051). Today opens
// them over its timeline; until it does, and for screenshots, each opens on its own here,
// in local and testing only: /dev/screens/<fixture>?view=<name> renders it from a fixture,
// and its path reads the server's day. The Today screen's own route goes first here, so
// /dev/screens/today_show without ?view keeps opening the screen, not a sheet.
const sheet = (path: string, name: string, component: RouteRecordRaw['component'], screen = 'today_show', fixture = true): RouteRecordRaw =>
    ({ path: `/dev/today/${path}`, name, component, meta: { dev: true, shell: false, screen, fixture } }) as RouteRecordRaw;

export default [
    sheet('calendar', 'today-calendar', () => import('./sheets/TodayCalendarSheet.vue'), 'week_show'),
    sheet('workout-time', 'today-workout-time', () => import('./sheets/TodayWorkoutTimeSheet.vue')),
    sheet('add-meal', 'today-add-meal', () => import('./sheets/TodayAddMealSheet.vue')),
    sheet('move-day', 'today-move-day', () => import('./sheets/TodayMoveDaySheet.vue')),
    sheet('add-workout', 'add-workout', () => import('./sheets/AddWorkoutSheet.vue')),
    sheet('busy-period', 'busy-period', () => import('./sheets/BusyPeriodSheet.vue')),
    sheet('edit-schedule', 'edit-schedule', () => import('./sheets/EditScheduleSheet.vue')),
    sheet('weekday-change', 'weekday-change', () => import('./sheets/WeekdayChangeSheet.vue'), 'training_weekdays_change', false),
] satisfies RouteRecordRaw[];
