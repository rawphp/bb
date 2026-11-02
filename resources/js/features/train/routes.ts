import type { RouteRecordRaw } from 'vue-router';

// The Train tab's root (NAV-001).
export default [{ path: '/train', name: 'train', component: () => import('./TrainView.vue'), meta: { screen: 'train_show', tab: 'train' } }] satisfies RouteRecordRaw[];
