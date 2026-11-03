import type { RouteLocationNormalized, RouteRecordRaw } from 'vue-router';

// The workout screen (WO-001), opened from Today, Train, a mesocycle's week and the bar
// above the tabs: /workouts/<mesocycle>/<week>/<day>. /dev/screens/workout_show opens it
// from its fixture.
export default [
    {
        path: '/workouts/:mesocycleId/:week(\\d+)/:day(\\d+)',
        name: 'workout',
        component: () => import('./WorkoutView.vue'),
        props: (route: RouteLocationNormalized) => ({ mesocycleId: String(route.params.mesocycleId), week: Number(route.params.week), day: Number(route.params.day) }),
        meta: { screen: 'workout_show', tab: 'train' },
    },
] satisfies RouteRecordRaw[];
