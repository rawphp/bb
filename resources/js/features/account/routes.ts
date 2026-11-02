import type { RouteRecordRaw } from 'vue-router';

export default [
    { path: '/account', component: () => import('./AccountView.vue'), meta: { screen: 'account_show', tab: 'more' } },
] satisfies RouteRecordRaw[];
