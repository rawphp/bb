import type { RouteRecordRaw } from 'vue-router';

// Today's sheets: schedule (SCH-023, SCH-030 to SCH-039, SCH-050, SCH-051), away week
// (SCH-060) and missed sessions and weeks (SCH-057, SCH-058). Today opens them over its
// timeline; until it does, and for screenshots, each opens on its own here, in local and
// testing only: /dev/screens/<fixture>?view=<name> renders it from a fixture, and its path
// reads the server's day. Until the Today screen has a route, /dev/screens/today_show
// without ?view opens the first sheet; Today's route (#96) must go above these so that URL
// opens the screen.
const sheet = (path: string, name: string, component: RouteRecordRaw['component'], screen = 'today_show', fixture = true): RouteRecordRaw =>
    ({ path: `/dev/today/${path}`, name, component, meta: { dev: true, shell: false, screen, fixture } }) as RouteRecordRaw;

// Dev routes stay out of a production build (CAP-004), as features/dev's do.
export default (import.meta.env.DEV
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
