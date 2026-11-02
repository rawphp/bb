import { describe, expect, it } from 'vitest';
import { featureRoutes } from '@/app/router';
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
});
