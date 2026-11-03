import type { RouteRecordRaw } from 'vue-router';

// Settings' own pages (SET-010, SAFE-017, ACC-005). meta.fixture false: the page reads
// nothing, so /dev/screens/<screen> renders it without a fixture.
export default [
    { path: '/settings/eligibility', component: () => import('./EligibilityRecheckView.vue'), meta: { screen: 'eligibility_recheck_show', tab: 'more' } },
    { path: '/settings/delete-account', component: () => import('./DeleteAccountView.vue'), meta: { screen: 'account_delete', fixture: false, tab: 'more' } },
    { path: '/settings/tokens', component: () => import('./TokensView.vue'), meta: { screen: 'token_list', tab: 'more' } },
    { path: '/settings/health-bridge', component: () => import('./HealthBridgeView.vue'), meta: { screen: 'health_bridge_show', tab: 'more' } },
    // No tab: the More placeholder would link it on an instance without the agent (AGT-002).
    // Settings' Coach row, there only when the agent runs, is the way in.
    { path: '/settings/coach', component: () => import('./CoachSettingsView.vue'), meta: { screen: 'agent_status_show' } },
] satisfies RouteRecordRaw[];
