import type { RouteRecordRaw } from 'vue-router';

// Local and testing only: the router drops meta.dev routes unless the server flags them on.
export default [
    { path: '/dev/screens/:name', component: () => import('./ScreenView.vue'), props: true, meta: { dev: true } },
    { path: '/dev/gallery', component: () => import('./GalleryView.vue'), meta: { dev: true } },
] satisfies RouteRecordRaw[];
