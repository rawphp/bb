import type { RouteRecordRaw } from 'vue-router';

// The Saved meals manager (MEAL-071). The More menu links it (SET-020).
export default [
    { path: '/saved-meals', component: () => import('./SavedMealsView.vue'), meta: { screen: 'saved_meals_show', tab: 'more' } },
] satisfies RouteRecordRaw[];
