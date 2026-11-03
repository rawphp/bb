import type { RouteLocationNormalized, RouteRecordRaw } from 'vue-router';

// The workout screen (WO-001), opened from Today, Train, a mesocycle's week and the bar
// above the tabs: /workouts/<mesocycle>/<week>/<day>. /dev/screens/workout_show opens it
// from its fixture.
const workout = [
    {
        path: '/workouts/:mesocycleId/:week(\\d+)/:day(\\d+)',
        name: 'workout',
        component: () => import('./WorkoutView.vue'),
        props: (route: RouteLocationNormalized) => ({ mesocycleId: String(route.params.mesocycleId), week: Number(route.params.week), day: Number(route.params.day) }),
        meta: { screen: 'workout_show', tab: 'train' },
    },
] satisfies RouteRecordRaw[];

// The workout's sheets (WO-007, WO-008, WO-020 to WO-022, WO-030, WO-031, FB-001 to FB-009).
// The screen opens them over itself; for screenshots each also opens on its own, in local
// and testing only: /dev/screens/workout_show?view=<name> renders it from the workout
// fixture. The ids are the fixture's; the picker and swap sheets read the exercise library,
// so they get its fixture beside the workout (meta.fixtures), and look back its session.
const BENCH = '01JB8W3Q000000000000000010';
const sheet = (path: string, name: string, component: RouteRecordRaw['component'], props: Record<string, unknown> = {}, fixtures?: Record<string, string>): RouteRecordRaw =>
    ({ path: `/dev/workout/${path}`, name, component, props, meta: { dev: true, shell: false, screen: 'workout_show', fixture: true, fixtures } }) as RouteRecordRaw;

// Dev routes stay out of a production build (CAP-004), as features/dev's do.
const sheets = (import.meta.env.DEV
    ? [
          sheet('feedback', 'workout-feedback', () => import('./sheets/FeedbackSheet.vue'), { muscle: 'chest' }),
          sheet('set-menu', 'workout-set-menu', () => import('./sheets/SetMenuSheet.vue'), { setId: '01JB8W3Q000000000000000100' }),
          sheet('rir-info', 'workout-rir-info', () => import('./sheets/RIRInfoSheet.vue')),
          sheet('help', 'workout-help', () => import('./sheets/WorkoutHelpSheet.vue')),
          sheet('look-back', 'workout-look-back', () => import('./sheets/LookBackSheet.vue'), {}, { session: 'workout_show' }),
          sheet('look-back/detail', 'workout-look-back-detail', () => import('./sheets/LookBackDetail.vue'), { mesocycleId: '01JB8W3Q000000000000000900', week: 2, day: 2 }),
          sheet('exercise-picker', 'workout-exercise-picker', () => import('./sheets/ExercisePickerSheet.vue'), {}, { library: 'exercises_show' }),
          sheet('swap', 'workout-swap', () => import('./sheets/SwapSheet.vue'), { exerciseId: BENCH }, { library: 'exercises_show' }),
          sheet('note/pin', 'workout-note-pin', () => import('./sheets/NoteSheet.vue'), { exerciseId: BENCH, pinned: true }),
          sheet('note/add', 'workout-note-add', () => import('./sheets/NoteSheet.vue'), { exerciseId: BENCH, pinned: false }),
          sheet('relabel', 'workout-relabel', () => import('./sheets/RelabelSheet.vue')),
          sheet('reorder', 'workout-reorder', () => import('./sheets/ReorderSheet.vue')),
      ]
    : []) satisfies RouteRecordRaw[];

export default [...workout, ...sheets] satisfies RouteRecordRaw[];
