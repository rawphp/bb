import type { RouteRecordRaw } from 'vue-router';

// The Saved meals manager (MEAL-071). The More placeholder links it until V_MORE's menu does.
export default [
    { path: '/saved-meals', component: () => import('./SavedMealsView.vue'), meta: { screen: 'saved_meals_show', tab: 'more' } },
] satisfies RouteRecordRaw[];
