import type { RouteRecordRaw } from 'vue-router';

// The More tab's root (SET-020). It reads nothing, so /dev/screens/more renders it without a fixture.
export default [{ path: '/more', name: 'more', component: () => import('./MoreView.vue'), meta: { screen: 'more', fixture: false, tab: 'more' } }] satisfies RouteRecordRaw[];
