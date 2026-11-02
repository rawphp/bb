import type { RouteRecordRaw } from 'vue-router';

// Train's mesocycle screens (MESO-001 to MESO-045). The ones that read train_show or
// mesocycle_show share it, so each route is named: /dev/screens/<fixture>?view=<name>.
export default [
    { path: '/train/current', name: 'current-mesocycle', component: () => import('./CurrentMesocycleView.vue'), meta: { screen: 'train_show', tab: 'train' } },
    { path: '/train/mesocycles', name: 'mesocycles', component: () => import('./MesocyclesView.vue'), meta: { screen: 'train_show', tab: 'train' } },
    { path: '/train/mesocycles/:mesocycleId', name: 'mesocycle', component: () => import('./MesocycleView.vue'), props: true, meta: { screen: 'mesocycle_show', tab: 'train' } },
    {
        path: '/train/mesocycles/:mesocycleId/rates',
        name: 'progression-rates',
        component: () => import('./ProgressionRatesView.vue'),
        props: true,
        meta: { screen: 'mesocycle_show', tab: 'train' },
    },
] satisfies RouteRecordRaw[];
