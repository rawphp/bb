import type { RouteRecordRaw } from 'vue-router';

// Today's sheets open over the Today screen, not on a page of their own, so these routes are
// the dev screens only: /dev/screens/<fixture>, with ?view=<name> for the two that read today_show.
export default [
    { path: '/dev/today/away-week', name: 'away-week-sheet', component: () => import('./sheets/AwayWeekSheet.vue'), meta: { dev: true, shell: false, screen: 'away_week_show' } },
    { path: '/dev/today/missed-weeks', name: 'today-missed-weeks-sheet', component: () => import('./sheets/TodayMissedWeeksSheet.vue'), meta: { dev: true, shell: false, screen: 'today_show' } },
    { path: '/dev/today/missed-day', name: 'today-missed-day-sheet', component: () => import('./sheets/TodayMissedDaySheet.vue'), meta: { dev: true, shell: false, screen: 'today_show' } },
] satisfies RouteRecordRaw[];
