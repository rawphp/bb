import type { RouteLocationNormalized, RouteRecordRaw } from 'vue-router';

// The meal screen (MEAL-001 to MEAL-011, UI-021). Today's meal cards and the Nutrition tab
// both open it here (NAV-002); opened from Today it stays in Today's stack. Today's menu
// opens it with ?action=<menu item> for the items the meal screen carries out (MEAL-070).
export default [
    {
        path: '/meals/:id',
        name: 'meal',
        component: () => import('./MealView.vue'),
        props: (route: RouteLocationNormalized) => ({ mealId: String(route.params.id), action: typeof route.query.action === 'string' ? route.query.action : undefined }),
        meta: { screen: 'meal_show', tab: 'nutrition' },
    },
] satisfies RouteRecordRaw[];
