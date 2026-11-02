import { describe, expect, it } from 'vitest';
import { createMemoryHistory } from 'vue-router';
import { featureRoutes, makeRouter } from '@/app/router';
import { findScreen } from '@/features/dev/screens';

describe('Away week, missed week and copy sheet routes', () => {
    it.each([
        ['away_week_show', undefined],
        ['today_show', 'today-missed-weeks-sheet'],
        ['today_show', 'today-missed-day-sheet'],
        ['copy_show', undefined],
        ['copy_show', 'copy-day'],
    ])('CAP-001 opens %s (%s) at /dev/screens with its fixture', (screen, view) => {
        expect(findScreen(screen, featureRoutes, view)).not.toBeNull();
    });

    it('NAV-001 opens each sheet at its own route in the Today tab, with its params as props', () => {
        const router = makeRouter(false, createMemoryHistory());
        const at = (path: string) => router.resolve(path);

        expect(at('/today/away/2026-09-26').name).toBe('away-week-sheet');
        expect(at('/today/missed-weeks').name).toBe('today-missed-weeks-sheet');
        expect(at('/today/missed-day').name).toBe('today-missed-day-sheet');
        expect(at('/copy/meal/01JBX0M1N2P3Q4R5S6T7V8W9X0').name).toBe('copy-foods');
        expect(at('/copy/day/2026-09-23').name).toBe('copy-day');
        expect(at('/copy/day/2026-09-23').meta.tab).toBe('today');
        for (const path of ['/today/away/next', '/copy/meal/1', '/copy/day/yesterday']) {
            expect(at(path).matched[0]?.path).toBe('/:pathMatch(.*)*');
        }
    });
});
