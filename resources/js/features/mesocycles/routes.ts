import type { RouteRecordRaw } from 'vue-router';

// Train's mesocycle screens (MESO-001 to MESO-045). The ones that read train_show or
// mesocycle_show share it, so each route is named: /dev/screens/<fixture>?view=<name>.
// :mesocycleId takes only a ULID, so /train/mesocycles/build (the build flow, #109)
// is never read as a mesocycle id; it is the build flow's own route.
const ULID = '([0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26})';

export default [
    { path: '/train/current', name: 'current-mesocycle', component: () => import('./CurrentMesocycleView.vue'), meta: { screen: 'train_show', tab: 'train' } },
    { path: '/train/mesocycles', name: 'mesocycles', component: () => import('./MesocyclesView.vue'), meta: { screen: 'train_show', tab: 'train' } },
    { path: `/train/mesocycles/:mesocycleId${ULID}`, name: 'mesocycle', component: () => import('./MesocycleView.vue'), props: true, meta: { screen: 'mesocycle_show', tab: 'train' } },
    {
        path: `/train/mesocycles/:mesocycleId${ULID}/rates`,
        name: 'progression-rates',
        component: () => import('./ProgressionRatesView.vue'),
        props: true,
        meta: { screen: 'mesocycle_show', tab: 'train' },
    },
    // The template library (MESO-010 to MESO-015). Both read templates_show; the library comes first, so it is what /dev/screens/templates_show shows.
    { path: '/train/templates', name: 'templates', component: () => import('./templates/TemplateLibraryView.vue'), meta: { screen: 'templates_show', tab: 'train' } },
    {
        // A template's id is the library's own, not a ULID.
        path: '/train/templates/:templateId([^/]+)',
        name: 'template-detail',
        component: () => import('./templates/TemplateDetailView.vue'),
        props: true,
        meta: { screen: 'templates_show', tab: 'train' },
    },
] satisfies RouteRecordRaw[];
