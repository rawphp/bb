import { mount, type VueWrapper } from '@vue/test-utils';
import { expect, vi } from 'vitest';
import type { Component } from 'vue';
import { write } from '@/app/call';
import type { TodayShowOutput } from '@/contracts/today_show';
import type { WeekShowOutput } from '@/contracts/week_show';
import { inputViolations, screenFixture } from '@/testing/schemas';

// Tests only: a Today sheet mounted on a screen fixture, with the capability calls mocked
// by the test file (vi.mock('@/app/call')). The sheet teleports nowhere, so it renders in place.

export const today = (state?: string) => screenFixture<TodayShowOutput>(state ? `today_show.${state}` : 'today_show');
export const week = (state?: string) => screenFixture<WeekShowOutput>(state ? `week_show.${state}` : 'week_show');

export function sheet(component: Component, props: Record<string, unknown> = {}): VueWrapper {
    return mount(component, { props, attachTo: document.body, global: { stubs: { teleport: true } } });
}

/** Each write sent, as [name, input], checked against that write's schema snapshot. */
export function sent(): [string, Record<string, unknown>][] {
    return vi.mocked(write).mock.calls.map(([name, input]) => {
        expect(inputViolations(name, input), name).toEqual([]);
        return [name, input as Record<string, unknown>];
    });
}

/** A 422 refusal in the server's envelope (CAP-012). */
export function refusal(message: string, field = 'date'): { error: unknown } {
    return { error: { code: 'validation_failed', message, violations: [{ field, message }] } };
}
