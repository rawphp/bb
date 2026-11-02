import { describe, expect, it } from 'vitest';
import { featureRoutes, routesFor } from '@/app/router';
import { findScreen } from '@/features/dev/screens';

describe('Away week, missed week and copy sheet dev screens', () => {
    it.each([
        ['away_week_show', 'away-week'],
        ['today_show', 'today-missed-weeks'],
        ['today_show', 'today-missed-day'],
        ['copy_show', 'copy'],
    ])('CAP-001 opens %s (?view=%s) at /dev/screens with its fixture', (screen, view) => {
        expect(findScreen(screen, featureRoutes, view)).not.toBeNull();
    });

    it('CAP-004 leaves the sheets out when the server has not turned dev routes on', () => {
        const paths = routesFor(false).map((route) => route.path);

        for (const path of ['/dev/today/away-week', '/dev/today/missed-weeks', '/dev/today/missed-day', '/dev/copy']) {
            expect(paths).not.toContain(path);
        }
    });
});
