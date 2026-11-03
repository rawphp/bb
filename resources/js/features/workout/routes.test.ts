import { describe, expect, it } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { featureRoutes, makeRouter } from '@/app/router';
import { findScreen, routeProps, screenFixtures, takesFixture } from '@/features/dev/screens';
import routes from './routes';

const BENCH = '01JB8W3Q000000000000000010';
const SET_1 = '01JB8W3Q000000000000000100';
const LIBRARY = { library: 'exercises_show' };

// Each sheet opens at /dev/screens/workout_show?view=<name> from the workout fixture, with
// the ids it needs in its route's props and the library beside it where it reads one.
const sheets: [view: string, props: Record<string, unknown>, fixtures: Record<string, string>][] = [
    ['workout-feedback', { muscle: 'chest' }, {}],
    ['workout-set-menu', { setId: SET_1 }, {}],
    ['workout-rir-info', {}, {}],
    ['workout-help', {}, {}],
    ['workout-look-back', {}, { session: 'workout_show' }],
    ['workout-look-back-detail', { mesocycleId: '01JB8W3Q000000000000000900', week: 2, day: 2 }, {}],
    ['workout-exercise-picker', {}, LIBRARY],
    ['workout-swap', { exerciseId: BENCH }, LIBRARY],
    ['workout-note-pin', { exerciseId: BENCH, pinned: true }, {}],
    ['workout-note-add', { exerciseId: BENCH, pinned: false }, {}],
    ['workout-relabel', {}, {}],
    ['workout-reorder', {}, {}],
];

describe('workout routes', () => {
    it('VIEW-001 /dev/screens/workout_show opens the workout screen from its fixture', () => {
        expect(findScreen('workout_show', featureRoutes)).not.toBeNull();
        expect(takesFixture('workout_show', featureRoutes)).toBe(true);
    });

    it('WO-001 /workouts/<mesocycle>/<week>/<day> opens the session it names, in the Train tab', () => {
        const router = createRouter({ history: createMemoryHistory(), routes });

        const route = router.resolve('/workouts/m1/3/2');
        expect(route.name).toBe('workout');
        expect(route.meta.tab).toBe('train');
        const props = (route.matched[0]!.props as { default: (r: typeof route) => unknown }).default(route);
        expect(props).toEqual({ mesocycleId: 'm1', week: 3, day: 2 });
        expect(router.resolve('/workouts/m1/three/2').matched).toHaveLength(0);
    });

    it('VIEW-001 /dev/screens/workout_show still opens the workout screen, ahead of its sheets', () => {
        expect(featureRoutes.find((r) => r.meta?.screen === 'workout_show')?.name).toBe('workout');
    });

    it.each(sheets)('VIEW-005 opens %s at /dev/screens/workout_show?view=%s from the workout fixture', (view, props, fixtures) => {
        expect(findScreen('workout_show', featureRoutes, view), view).not.toBeNull();
        expect(takesFixture('workout_show', featureRoutes, view), view).toBe(true);
        expect(routeProps('workout_show', featureRoutes, view), view).toEqual(props);
        expect(screenFixtures('workout_show', featureRoutes, view), view).toEqual(fixtures);
    });

    it('VIEW-005 keeps the sheets out of the app outside local and testing', () => {
        const router = makeRouter(false, createMemoryHistory());

        expect(router.resolve('/dev/workout/set-menu').matched[0]?.path).toBe('/:pathMatch(.*)*');
        expect(makeRouter(true, createMemoryHistory()).resolve('/dev/workout/set-menu').name).toBe('workout-set-menu');
    });
});
