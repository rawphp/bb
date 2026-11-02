import { mount, type VueWrapper } from '@vue/test-utils';
import { expect, vi } from 'vitest';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import { writeNow } from '@/app/call';
import type { OnboardingShowOutput } from '@/contracts/onboarding_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import OnboardingView from './OnboardingView.vue';

// Tests only: setup mounted on one of its screen fixtures, with the capability calls
// mocked by the test file (vi.mock('@/app/call')).

export const fixture = (state?: string) => screenFixture<OnboardingShowOutput>(state ? `onboarding_show.${state}` : 'onboarding_show');

/** A router that knows setup and Today, so "Start my plan" can leave for Today. */
export function testRouter(): Router {
    const blank = { render: () => null };
    return createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: blank }, { path: '/onboarding', component: blank }] });
}

export function view(payload?: OnboardingShowOutput, options: { pending?: boolean; router?: Router } = {}): VueWrapper {
    return mount(OnboardingView, {
        props: { payload, pending: options.pending ?? false },
        attachTo: document.body,
        global: { plugins: [options.router ?? testRouter()] },
    });
}

/** Each write sent, as [name, input], checked against that write's schema snapshot. */
export function writes(): [string, Record<string, unknown>][] {
    return vi.mocked(writeNow).mock.calls.map(([name, input]) => {
        expect(inputViolations(name, input)).toEqual([]);
        return [name, input as Record<string, unknown>];
    });
}

/** Each onboarding_answer sent, checked against its schema snapshot. */
export function sent(): Record<string, unknown>[] {
    return writes().map(([name, input]) => {
        expect(name).toBe('onboarding_answer');
        return input;
    });
}

/** The answers of each onboarding_answer sent. */
export function answers(): Record<string, unknown>[] {
    return sent().map((input) => input.answers as Record<string, unknown>);
}

export function next(wrapper: VueWrapper) {
    return wrapper.get('button[data-step="next"]');
}

export function back(wrapper: VueWrapper) {
    return wrapper.get('button[data-step="back"]');
}

export function radio(wrapper: VueWrapper, group: string, title: string) {
    const row = wrapper
        .get(`[role="radiogroup"][aria-label="${group}"]`)
        .findAll('[role="radio"]')
        .find((r) => r.text().startsWith(title) || r.attributes('aria-label') === title);
    if (!row) throw new Error(`No ${title} in ${group}`);
    return row;
}

/** A − or + of the stepper named `label`. */
export function stepper(wrapper: VueWrapper, label: string, which: 'Fewer' | 'More') {
    return wrapper.get(`button[aria-label="${which}: ${label}"]`);
}

/** Sets a time, date, text or range input; setValue fires input and change, as the browser does on commit. */
export async function commit(input: ReturnType<VueWrapper['get']>, value: string): Promise<void> {
    await input.setValue(value);
}
