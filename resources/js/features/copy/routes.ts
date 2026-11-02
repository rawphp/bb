import type { RouteRecordRaw } from 'vue-router';

// Copy foods (MEAL-060) and Copy day (SCH-034) open over a meal or Today; until those
// screens open them, and for screenshots, the sheet opens on its own here, in local and
// testing only: /dev/screens/copy_show renders it from a fixture.
// Dev routes stay out of a production build (CAP-004), as features/dev's do.
export default (import.meta.env.DEV
    ? [{ path: '/dev/copy', name: 'copy', component: () => import('./CopySheet.vue'), meta: { dev: true, shell: false, screen: 'copy_show' } }]
    : []) satisfies RouteRecordRaw[];
