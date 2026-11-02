import type { RouteRecordRaw } from 'vue-router';

// Copy foods (MEAL-060) and Copy day (SCH-034). A meal or Today opens the sheet over itself;
// each also has its own route, where closing it goes back.
const ULID = '([0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26})';
const DATE = '(\\d{4}-\\d{2}-\\d{2})';

export default [
    {
        path: `/copy/meal/:mealId${ULID}`,
        name: 'copy-foods',
        component: () => import('./CopySheet.vue'),
        props: (route) => ({ mealId: route.params.mealId, page: true }),
        meta: { screen: 'copy_show', tab: 'today' },
    },
    {
        path: `/copy/day/:sourceDate${DATE}`,
        name: 'copy-day',
        component: () => import('./CopySheet.vue'),
        props: (route) => ({ sourceDate: route.params.sourceDate, page: true }),
        meta: { screen: 'copy_show', tab: 'today' },
    },
] satisfies RouteRecordRaw[];
