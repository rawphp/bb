import { describe, expect, it } from 'vitest';
import { createMemoryHistory } from 'vue-router';
import { featureRoutes, makeRouter } from '@/app/router';
import { findScreen, takesFixture } from '@/features/dev/screens';

const sheets = {
    'today-calendar': 'week_show',
    'today-workout-time': 'today_show',
    'today-add-meal': 'today_show',
    'today-move-day': 'today_show',
    'add-workout': 'today_show',
    'busy-period': 'today_show',
    'edit-schedule': 'today_show',
} as const;

describe('Today sheet routes', () => {
    it('VIEW-005 opens each schedule sheet at /dev/screens/<fixture>?view=<name>', () => {
        for (const [view, fixture] of Object.entries(sheets)) {
            expect(findScreen(fixture, featureRoutes, view), view).not.toBeNull();
            expect(takesFixture(fixture, featureRoutes, view), view).toBe(true);
        }
        expect(findScreen('training_weekdays_change', featureRoutes, 'weekday-change')).not.toBeNull();
        expect(takesFixture('training_weekdays_change', featureRoutes, 'weekday-change')).toBe(false);
    });

    it('VIEW-005 keeps the sheets out of the app outside local and testing', () => {
        const router = makeRouter(false, createMemoryHistory());

        expect(router.resolve('/dev/today/add-meal').matched[0]?.path).toBe('/:pathMatch(.*)*');
        expect(makeRouter(true, createMemoryHistory()).resolve('/dev/today/add-meal').name).toBe('today-add-meal');
    });
});
