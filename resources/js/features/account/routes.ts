import type { RouteRecordRaw } from 'vue-router';

export default [
    { path: '/account', component: () => import('./AccountView.vue'), meta: { screen: 'account_show', tab: 'more' } },
    // ACC-001. Outside the shell: signed out, there are no tabs to show. It reads nothing,
    // so /dev/screens/sign_in renders it without a fixture.
    { path: '/sign-in', component: () => import('./SignInView.vue'), meta: { screen: 'sign_in', fixture: false, shell: false } },
] satisfies RouteRecordRaw[];
