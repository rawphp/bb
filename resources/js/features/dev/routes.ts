import type { RouteRecordRaw } from 'vue-router';

// Local and testing only: the router drops meta.dev routes unless the server flags them on.
export default [
    { path: '/dev/screens/:name', component: () => import('./ScreenView.vue'), props: true, meta: { dev: true, shell: false } },
    // Settings → Developer (SET-011) on its own page, until the Settings list mounts DeveloperSection.
    { path: '/dev/clock', component: () => import('@/features/settings/DeveloperView.vue'), meta: { dev: true, screen: 'developer', fixture: false, tab: 'more' } },
    { path: '/dev/gallery', component: () => import('./GalleryView.vue'), meta: { dev: true, shell: false } },
] satisfies RouteRecordRaw[];
