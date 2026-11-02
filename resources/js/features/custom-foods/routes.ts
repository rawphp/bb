import type { RouteRecordRaw } from 'vue-router';

// The Custom foods manager (FOOD-061). The More placeholder links it until V_MORE's menu does.
export default [
    { path: '/custom-foods', component: () => import('./CustomFoodsView.vue'), meta: { screen: 'custom_foods_show', tab: 'more' } },
] satisfies RouteRecordRaw[];
