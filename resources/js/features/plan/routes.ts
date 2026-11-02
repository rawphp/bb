import type { LocationQueryValue, RouteLocationNormalized, RouteRecordRaw } from 'vue-router';

// The plan screens (PLAN-003 to PLAN-006, PH-006, PH-007): the timeline, a phase, and
// the running phase with an action already open, for the More menu's Adjust phase length
// and End current phase; then add a phase (PLAN-006), the 24-week plans (PLAN-030) and the
// phase transition (PH-030), which read plan_show too. /plan comes first, so
// /dev/screens/plan_show opens the plan; the others open with ?view=<name>, and
// the running-phase screens read the running phase's phase_show from the server, as
// CurrentMesocycleView does. :phaseId takes a ULID (a
// client-made id, CAP-019) or a UUID (one the server made), so a malformed id is not found.
const ID = '([0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})';

const queryText = (value: LocationQueryValue | LocationQueryValue[] | undefined): string | undefined => (typeof value === 'string' ? value : undefined);

export default [
    { path: '/plan', name: 'plan', component: () => import('./PlanView.vue'), meta: { screen: 'plan_show', tab: 'train' } },
    { path: `/plan/phases/:phaseId${ID}`, name: 'phase', component: () => import('./PhaseDetailView.vue'), props: true, meta: { screen: 'phase_show', tab: 'train' } },
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
    {
        path: '/train/plan/add-phase',
        name: 'add-phase',
        component: () => import('./AddPhaseView.vue'),
        // A suggestion links here with the type and weeks to open on (PH-045).
        props: (route: RouteLocationNormalized) => ({ type: queryText(route.query.type), weeks: queryText(route.query.weeks) }),
        meta: { screen: 'plan_show', tab: 'train' },
    },
    { path: '/train/plan/prebuilt', name: 'prebuilt-plans', component: () => import('./PrebuiltPlansView.vue'), meta: { screen: 'plan_show', tab: 'train' } },
    { path: '/train/plan/transition', name: 'phase-transition', component: () => import('./PhaseTransitionView.vue'), meta: { screen: 'plan_show', tab: 'train' } },
] satisfies RouteRecordRaw[];
