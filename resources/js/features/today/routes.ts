import type { RouteRecordRaw } from 'vue-router';

// Today's away week and missed week sheets (SCH-057, SCH-058, SCH-060). Today opens them
// over itself; until it does, and for screenshots, each opens on its own here, in local
// and testing only: /dev/screens/<fixture>?view=<name> renders it from a fixture.
// Today's route (#96) must go above these, so /dev/screens/today_show opens the screen.
const sheet = (path: string, name: string, component: RouteRecordRaw['component'], screen = 'today_show'): RouteRecordRaw =>
    ({ path: `/dev/today/${path}`, name, component, meta: { dev: true, shell: false, screen } }) as RouteRecordRaw;

// Dev routes stay out of a production build (CAP-004), as features/dev's do.
export default (import.meta.env.DEV
    ? [
          sheet('away-week', 'away-week', () => import('./sheets/AwayWeekSheet.vue'), 'away_week_show'),
          sheet('missed-weeks', 'today-missed-weeks', () => import('./sheets/TodayMissedWeeksSheet.vue')),
          sheet('missed-day', 'today-missed-day', () => import('./sheets/TodayMissedDaySheet.vue')),
      ]
    : []) satisfies RouteRecordRaw[];
