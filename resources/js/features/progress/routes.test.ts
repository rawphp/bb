import { describe, expect, it } from 'vitest';
import routes from './routes';

describe('progress routes', () => {
    it('NAV-001 CAP-004 put the Progress screens in the Progress tab, each reading its capability', () => {
        expect(routes.map((r) => [r.path, r.meta?.screen, r.meta?.tab])).toEqual([
            ['/progress', 'progress_show', 'progress'],
            ['/progress/exercises', undefined, 'progress'],
            ['/progress/exercises/:exerciseId', 'exercise_history_show', 'progress'],
        ]);
    });
});
