import type { RouteLocationNormalized, RouteRecordRaw } from 'vue-router';

// The Custom foods manager (FOOD-061). The More menu links it (SET-020).
// The Pantry's "Can't find it?" link (FOOD-062) opens it at /nutrition/custom-foods/new?name=,
// with a new food named after the search. That route has no tab, so it joins the Pantry's.
export default [
    { path: '/custom-foods', name: 'custom-foods', component: () => import('./CustomFoodsView.vue'), meta: { screen: 'custom_foods_show', tab: 'more' } },
    {
        path: '/nutrition/custom-foods/new',
        name: 'custom-food-new',
        component: () => import('./CustomFoodsView.vue'),
        props: (route: RouteLocationNormalized) => ({ create: typeof route.query.name === 'string' ? route.query.name : '' }),
        meta: { screen: 'custom_foods_show' },
    },
] satisfies RouteRecordRaw[];
