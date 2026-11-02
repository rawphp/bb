import type { RouteRecordRaw } from 'vue-router';

// Today's away week and missed week sheets (SCH-057, SCH-058, SCH-060). Today opens them
// over itself; each also has its own route, where closing it goes back. The two that read
// today_show are named: /dev/screens/today_show?view=<name>.
const DATE = '(\\d{4}-\\d{2}-\\d{2})';

export default [
    {
        path: `/today/away/:weekStart${DATE}`,
        name: 'away-week-sheet',
        component: () => import('./sheets/AwayWeekSheet.vue'),
        props: (route) => ({ weekStart: route.params.weekStart, page: true }),
        meta: { screen: 'away_week_show', tab: 'today' },
    },
    { path: '/today/missed-weeks', name: 'today-missed-weeks-sheet', component: () => import('./sheets/TodayMissedWeeksSheet.vue'), props: { page: true }, meta: { screen: 'today_show', tab: 'today' } },
    { path: '/today/missed-day', name: 'today-missed-day-sheet', component: () => import('./sheets/TodayMissedDaySheet.vue'), props: { page: true }, meta: { screen: 'today_show', tab: 'today' } },
] satisfies RouteRecordRaw[];
