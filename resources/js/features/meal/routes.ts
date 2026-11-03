import type { RouteLocationNormalized, RouteRecordRaw } from 'vue-router';

// The meal screen (MEAL-001 to MEAL-011, UI-021). Today's meal cards and the Nutrition tab
// both open it here (NAV-002); opened from Today it stays in Today's stack.
export default [
    {
        path: '/meals/:id',
        name: 'meal',
        component: () => import('./MealView.vue'),
        props: (route: RouteLocationNormalized) => ({ mealId: String(route.params.id) }),
        meta: { screen: 'meal_show', tab: 'nutrition' },
    },
] satisfies RouteRecordRaw[];
