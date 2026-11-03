import { expect, vi } from 'vitest';
import { writeNow } from '@/app/call';
import type { ReviewShowOutput } from '@/contracts/review_show';
import { inputViolations, screenFixture } from '@/testing/schemas';

// Tests only: the review_show fixtures, and each write sent checked against its schema
// snapshot. The test file mocks the capability calls (vi.mock('@/app/call')).

export const review = (state?: string) => screenFixture<ReviewShowOutput>(state ? `review_show.${state}` : 'review_show');

/** Every review_show fixture state; '' is the full one. */
export const states = ['', 'away', 'choose_own', 'drop_fatigue', 'drop_fatigue_deload', 'empty', 'planned_break', 'stall', 'training_only'];

/** Each write sent, as [name, input], checked against that write's schema snapshot. */
export function sent(): [string, Record<string, unknown>][] {
    return vi.mocked(writeNow).mock.calls.map(([name, input]) => {
        expect(inputViolations(name, input), name).toEqual([]);
        return [name, input as Record<string, unknown>];
    });
}
