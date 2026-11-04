import type { RouteLocationNormalized, RouteRecordRaw } from 'vue-router';

// Train's mesocycle screens (MESO-001 to MESO-045). The ones that read train_show or
// mesocycle_show share it, so each route is named: /dev/screens/<fixture>?view=<name>.
// :mesocycleId takes a ULID (a client-made id, CAP-019) or a UUID (one the server made),
// so /train/mesocycles/build (the build flow, #109) is never read as a mesocycle id, and a
// malformed id is not found.
const ID = '([0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})';

// A single-valued query key, or undefined.
function text(value: unknown): string | undefined {
    return typeof value === 'string' && value !== '' ? value : undefined;
}

export default [
    { path: '/train/current', name: 'current-mesocycle', component: () => import('./CurrentMesocycleView.vue'), meta: { screen: 'train_show', tab: 'train' } },
    { path: '/train/mesocycles', name: 'mesocycles', component: () => import('./MesocyclesView.vue'), meta: { screen: 'train_show', tab: 'train' } },
    { path: `/train/mesocycles/:mesocycleId${ID}`, name: 'mesocycle', component: () => import('./MesocycleView.vue'), props: true, meta: { screen: 'mesocycle_show', tab: 'train' } },
    {
        path: `/train/mesocycles/:mesocycleId${ID}/rates`,
        name: 'progression-rates',
        component: () => import('./ProgressionRatesView.vue'),
        props: true,
        meta: { screen: 'mesocycle_show', tab: 'train' },
    },
    // The create flow (MESO-002 to MESO-007): ?route=<template|copy_previous|builder|scratch>[&copy=<source mesocycle id>], or ?draft=<draft id>; ?step= opens a step.
    {
        path: '/train/mesocycles/build',
        name: 'mesocycle-build',
        component: () => import('./build/MesocycleBuildView.vue'),
        props: (route: RouteLocationNormalized) => ({ route: text(route.query.route), copy: text(route.query.copy), draft: text(route.query.draft), step: text(route.query.step) }),
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
