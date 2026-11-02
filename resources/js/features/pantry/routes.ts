import type { RouteLocationNormalized, RouteRecordRaw } from 'vue-router';
import { isGroup } from './options';

// The Pantry (FOOD-020 to FOOD-028). The meal screen opens it with ?meal=<meal id>, plus
// ?search= for the text typed there (UI-021) and ?group= for a short-macro hint
// (MEAL-007). Without a meal it is the Nutrition tab's food browser (NAV-001).
function text(value: unknown): string | undefined {
    return typeof value === 'string' && value !== '' ? value : undefined;
}

export default [
    {
        path: '/nutrition/pantry',
        name: 'pantry',
        component: () => import('./PantryView.vue'),
        props: (route: RouteLocationNormalized) => ({
            mealId: text(route.query.meal),
            // food_search takes at most 100 characters.
            search: (text(route.query.search) ?? '').slice(0, 100),
            group: isGroup(route.query.group) ? route.query.group : null,
        }),
        meta: { screen: 'food_search', tab: 'nutrition' },
    },
] satisfies RouteRecordRaw[];
