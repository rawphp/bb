import type { RouteRecordRaw } from 'vue-router';

export default [
    { path: '/progress', component: () => import('./ProgressTabView.vue'), meta: { screen: 'progress_show', tab: 'progress' } },
    { path: '/progress/exercises', component: () => import('./ExerciseListView.vue'), meta: { tab: 'progress' } },
    {
        path: '/progress/exercises/:exerciseId',
        component: () => import('./ExerciseHistoryView.vue'),
        props: true,
        meta: { screen: 'exercise_history_show', tab: 'progress' },
    },
] satisfies RouteRecordRaw[];
