import type { RouteRecordRaw } from 'vue-router';

// The plan screens (PLAN-003 to PLAN-006, PH-006, PH-007): the timeline, a phase, and
// the running phase with an action already open, for the More menu's Adjust phase length
// and End current phase. /plan comes first, so /dev/screens/plan_show opens the plan;
// the running-phase screens open with ?view=<name>. :phaseId takes only a ULID, so the
// add-phase and prebuilt-plan screens (#107) never read as a phase.
const ULID = '([0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26})';

export default [
    { path: '/plan', name: 'plan', component: () => import('./PlanView.vue'), meta: { screen: 'plan_show', tab: 'train' } },
    { path: `/plan/phases/:phaseId${ULID}`, name: 'phase', component: () => import('./PhaseDetailView.vue'), props: true, meta: { screen: 'phase_show', tab: 'train' } },
    {
        path: '/plan/current/length',
        name: 'adjust-phase-length',
        component: () => import('./CurrentPhaseView.vue'),
        props: { action: 'adjust-length' },
        meta: { screen: 'plan_show', tab: 'train' },
    },
    {
        path: '/plan/current/end',
        name: 'end-current-phase',
        component: () => import('./CurrentPhaseView.vue'),
        props: { action: 'end' },
        meta: { screen: 'plan_show', tab: 'train' },
    },
] satisfies RouteRecordRaw[];
