import { describe, expect, it } from 'vitest';
import { createMemoryHistory } from 'vue-router';
import { featureRoutes, makeRouter } from '@/app/router';
import { findScreen } from '@/features/dev/screens';

// The away week, missed week and copy sheets open over Today and the meal screens, so
// their only routes are the dev screens that render them from a fixture.
describe('Away week, missed week and copy sheet routes', () => {
    it.each([
        ['away_week_show', undefined],
        ['today_show', 'today-missed-weeks-sheet'],
        ['today_show', 'today-missed-day-sheet'],
        ['copy_show', undefined],
    ])('CAP-001 opens %s (%s) at /dev/screens with its fixture', (screen, view) => {
        expect(findScreen(screen, featureRoutes, view)).not.toBeNull();
    });

    it('CAP-001 keeps the sheets out of an instance without dev routes', () => {
        const router = makeRouter(false, createMemoryHistory());

        for (const path of ['/dev/today/away-week', '/dev/today/missed-weeks', '/dev/today/missed-day', '/dev/copy']) {
            expect(router.resolve(path).matched[0]?.path).toBe('/:pathMatch(.*)*');
        }
    });
});
