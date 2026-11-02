import type { RouteRecordRaw } from 'vue-router';

// Today's sheets at their own routes, so /dev/screens/<capability> finds each one with its
// fixture (meta.screen) and a link can open one for a day. Opened at its route, a sheet goes
// back to Today when it closes or saves, without the server's toast: the Today screen draws
// the sheets over itself and listens to @close and @saved, and shows the toast (UI-010).
const DATE = '(\\d{4}-\\d{2}-\\d{2})';

export default [
    { path: `/today/:date${DATE}/adjust`, component: () => import('./sheets/AdjustSheet.vue'), props: true, meta: { screen: 'day_adjust_show', tab: 'today' } },
    { path: `/today/:date${DATE}/redistribute`, component: () => import('./sheets/RedistributeSheet.vue'), props: true, meta: { screen: 'day_redistribute_show', tab: 'today' } },
    { path: `/today/:date${DATE}/optimize`, component: () => import('./sheets/TodayOptimizeSheet.vue'), props: true, meta: { screen: 'day_optimize_show', tab: 'today' } },
    // Reads nothing (meta.fixture false): /dev/screens/off_plan_log opens it on the clock's today.
    { path: `/today/:date${DATE}/off-plan`, component: () => import('./sheets/OffPlanSheet.vue'), props: true, meta: { screen: 'off_plan_log', fixture: false, tab: 'today' } },
] satisfies RouteRecordRaw[];
