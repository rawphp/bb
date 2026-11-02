import { describe, expect, it } from 'vitest';
import { createMemoryHistory } from 'vue-router';
import { makeRouter } from '@/app/router';

describe('Train routes', () => {
    it('NAV-001 gives the Train tab its root and its mesocycle screens', () => {
        const router = makeRouter(false, createMemoryHistory());
        const name = (path: string) => router.resolve(path).name;

        expect(name('/train')).toBe('train');
        expect(name('/train/current')).toBe('current-mesocycle');
        expect(name('/train/mesocycles')).toBe('mesocycles');
        expect(name('/train/mesocycles/01JB6Q3V8T2K9WZ4M5N7P1R3S5')).toBe('mesocycle');
        expect(name('/train/mesocycles/01JB6Q3V8T2K9WZ4M5N7P1R3S5/rates')).toBe('progression-rates');
        expect(router.resolve('/train/mesocycles/x').meta.tab).toBe('train');
    });
});
