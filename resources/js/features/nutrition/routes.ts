import type { RouteRecordRaw } from 'vue-router';

// The Nutrition tab's root (NAV-001).
export default [
    { path: '/nutrition', name: 'nutrition', component: () => import('./NutritionView.vue'), meta: { screen: 'nutrition_show', tab: 'nutrition' } },
] satisfies RouteRecordRaw[];
