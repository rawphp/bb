import type { RouteRecordRaw } from 'vue-router';

// Plan: add a phase (PLAN-006), the 24-week plans (PLAN-030) and the phase transition
// (PH-030). All three read plan_show, so each route is named:
// /dev/screens/plan_show?view=<name>.
export default [
    { path: '/train/plan/add-phase', name: 'add-phase', component: () => import('./AddPhaseView.vue'), meta: { screen: 'plan_show', tab: 'train' } },
    { path: '/train/plan/prebuilt', name: 'prebuilt-plans', component: () => import('./PrebuiltPlansView.vue'), meta: { screen: 'plan_show', tab: 'train' } },
    { path: '/train/plan/transition', name: 'phase-transition', component: () => import('./PhaseTransitionView.vue'), meta: { screen: 'plan_show', tab: 'train' } },
] satisfies RouteRecordRaw[];
