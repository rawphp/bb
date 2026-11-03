import type { RouteRecordRaw } from 'vue-router';

// Settings and its own pages (SET-001 to SET-011, SAFE-017, ACC-005, NOTIF-007). Settings,
// its profile, schedule, diet filter and notification pages all read settings_show; /settings
// comes first, so /dev/screens/settings_show opens Settings and the others open with
// ?view=<name>. meta.fixture false: the page reads nothing, so /dev/screens/<screen>
// renders it without a fixture.
export default [
    { path: '/settings', name: 'settings', component: () => import('./SettingsView.vue'), meta: { screen: 'settings_show', tab: 'more' } },
    { path: '/settings/profile', name: 'settings-profile', component: () => import('./ProfileEditView.vue'), meta: { screen: 'settings_show', tab: 'more' } },
    { path: '/settings/schedule', name: 'settings-schedule', component: () => import('./ScheduleEditView.vue'), meta: { screen: 'settings_show', tab: 'more' } },
    { path: '/settings/diet-filters', name: 'settings-diet-filters', component: () => import('./DietFiltersView.vue'), meta: { screen: 'settings_show', tab: 'more' } },
    { path: '/settings/notifications', name: 'settings-notifications', component: () => import('./NotificationSettingsView.vue'), meta: { screen: 'settings_show', tab: 'more' } },
    { path: '/settings/eligibility', component: () => import('./EligibilityRecheckView.vue'), meta: { screen: 'eligibility_recheck_show', tab: 'more' } },
    { path: '/settings/delete-account', component: () => import('./DeleteAccountView.vue'), meta: { screen: 'account_delete', fixture: false, tab: 'more' } },
    { path: '/settings/tokens', component: () => import('./TokensView.vue'), meta: { screen: 'token_list', tab: 'more' } },
    { path: '/settings/health-bridge', component: () => import('./HealthBridgeView.vue'), meta: { screen: 'health_bridge_show', tab: 'more' } },
    // No tab: the More placeholder would link it on an instance without the agent (AGT-002).
    // Settings' Coach row, there only when the agent runs, is the way in.
    { path: '/settings/coach', component: () => import('./CoachSettingsView.vue'), meta: { screen: 'agent_status_show' } },
] satisfies RouteRecordRaw[];
