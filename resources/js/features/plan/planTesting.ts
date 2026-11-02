import { expect, vi } from 'vitest';
import { writeNow } from '@/app/call';
import type { PlanShowOutput } from '@/contracts/plan_show';
import { inputViolations, screenFixture } from '@/testing/schemas';

// Tests only: the plan_show fixtures, and each write sent checked against its schema
// snapshot. The test file mocks the capability calls (vi.mock('@/app/call')).

export const plan = (state?: string) => screenFixture<PlanShowOutput>(state ? `plan_show.${state}` : 'plan_show');

/** Each write sent, as [name, input], checked against that write's schema snapshot. */
export function sent(): [string, Record<string, unknown>][] {
    return vi.mocked(writeNow).mock.calls.map(([name, input]) => {
        expect(inputViolations(name, input), name).toEqual([]);
        return [name, input as Record<string, unknown>];
    });
}
