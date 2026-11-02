import type { RouteRecordRaw } from 'vue-router';

// The outbox (OFF-010), opened from the More menu's sync line. It reads no capability.
export default [{ path: '/sync', component: () => import('./OutboxView.vue'), meta: { tab: 'more' } }] satisfies RouteRecordRaw[];
