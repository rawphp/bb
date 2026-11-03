import type { RouteRecordRaw } from 'vue-router';

// Train → Exercises (EX-001 to EX-013): the library, and one exercise with its Detail and History tabs.
export default [
    { path: '/exercises', name: 'exercises', component: () => import('./ExerciseLibraryView.vue'), meta: { screen: 'exercises_show', tab: 'train' } },
    {
        path: '/exercises/:exerciseId',
        name: 'exercise',
        component: () => import('./ExerciseView.vue'),
        props: true,
        meta: { screen: 'exercise_show', tab: 'train' },
    },
] satisfies RouteRecordRaw[];
