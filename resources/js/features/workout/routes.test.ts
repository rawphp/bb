import { describe, expect, it } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { featureRoutes } from '@/app/router';
import { findScreen, takesFixture } from '@/features/dev/screens';
import routes from './routes';

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
});
