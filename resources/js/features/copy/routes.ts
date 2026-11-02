import type { RouteRecordRaw } from 'vue-router';

// Copy foods and Copy day open over a meal or Today, not on a page of their own, so this
// route is the dev screen only: /dev/screens/copy_show.
export default [
    { path: '/dev/copy', name: 'copy-sheet', component: () => import('./CopySheet.vue'), meta: { dev: true, shell: false, screen: 'copy_show' } },
] satisfies RouteRecordRaw[];
