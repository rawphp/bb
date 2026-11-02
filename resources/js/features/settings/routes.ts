import type { RouteRecordRaw } from 'vue-router';

// Settings' own pages (SET-010, SAFE-017, ACC-005). meta.fixture false: the page reads
// nothing, so /dev/screens/<screen> renders it without a fixture.
export default [
    { path: '/settings/eligibility', component: () => import('./EligibilityRecheckView.vue'), meta: { screen: 'eligibility_recheck_show', tab: 'more' } },
    { path: '/settings/delete-account', component: () => import('./DeleteAccountView.vue'), meta: { screen: 'account_delete', fixture: false, tab: 'more' } },
    { path: '/settings/tokens', component: () => import('./TokensView.vue'), meta: { screen: 'token_list', tab: 'more' } },
    { path: '/settings/coach', component: () => import('./CoachSettingsView.vue'), meta: { screen: 'agent_status_show', tab: 'more' } },
] satisfies RouteRecordRaw[];
