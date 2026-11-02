import type { RouteRecordRaw } from 'vue-router';

// Local and testing only: the router drops meta.dev routes unless the server flags them on.
export default [
    { path: '/dev/screens/:name', component: () => import('./ScreenView.vue'), props: (route) => ({ name: route.params.name, view: typeof route.query.view === 'string' ? route.query.view : undefined }), meta: { dev: true, shell: false } },
    { path: '/dev/gallery', component: () => import('./GalleryView.vue'), meta: { dev: true, shell: false } },
] satisfies RouteRecordRaw[];
