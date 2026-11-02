import { describe, expect, it } from 'vitest';
import { createMemoryHistory } from 'vue-router';
import { featureRoutes, makeRouter } from '@/app/router';
import { findScreen, takesFixture } from '@/features/dev/screens';

describe('Scan routes', () => {
    it('VIEW-005 opens the scanner in each fixture\'s state at /dev/screens/<fixture>', () => {
        for (const fixture of ['food_barcode_lookup', 'scan_photo', 'scan_label']) {
            expect(findScreen(fixture, featureRoutes), fixture).not.toBeNull();
            expect(takesFixture(fixture, featureRoutes), fixture).toBe(true);
        }
        expect(findScreen('scan', featureRoutes)).not.toBeNull();
        expect(takesFixture('scan', featureRoutes)).toBe(false);
    });

    it('VIEW-005 keeps the scanner\'s dev routes out of the app outside local and testing', () => {
        expect(makeRouter(false, createMemoryHistory()).resolve('/dev/scan').matched[0]?.path).toBe('/:pathMatch(.*)*');
        expect(makeRouter(true, createMemoryHistory()).resolve('/dev/scan').name).toBe('scan');
    });
});
