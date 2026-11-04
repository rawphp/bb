import type { RouteRecordRaw } from 'vue-router';

const DATE = '(\\d{4}-\\d{2}-\\d{2})';

// The Nutrition tab's root (NAV-001): the server's today at /nutrition, another day of the
// plan at /nutrition/<date> (NAV-005). It shows two reads, so /dev/screens/nutrition_show hands
// it the week strip's fixture too (meta.fixtures).
const meta = { screen: 'nutrition_show', fixtures: { week: 'week_show' }, tab: 'nutrition' } as const;

export default [
    { path: '/nutrition', name: 'nutrition', component: () => import('./NutritionView.vue'), meta },
    { path: `/nutrition/:date${DATE}`, name: 'nutrition-day', component: () => import('./NutritionView.vue'), props: true, meta },
] satisfies RouteRecordRaw[];
