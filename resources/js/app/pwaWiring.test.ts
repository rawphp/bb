import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

// holdUpdates() and offerInstall() are called by screens later issues build (docs/plan/build-plan.md):
// the workout view (V_WO1) holds updates for a workout (useHoldUpdates); the end of onboarding (V_ONB2) or Today
// (V_TODAY1) offers the install. These tests fail as soon as such a screen lands without the call,
// so that issue has to wire it, and keep start-up honest until then.

const sources: Record<string, string> = import.meta.glob('../features/**/*.{vue,ts}', { query: '?raw', import: 'default', eager: true });
const main = readFileSync('resources/js/app/main.ts', 'utf8');

const screens = (names: string[]) => Object.keys(sources).filter((path) => names.some((name) => path.endsWith(`/${name}.vue`)));
const calling = (fn: string) => Object.keys(sources).filter((path) => !path.includes('.test.') && new RegExp(`\\b${fn}\\(`).test(sources[path]!));

describe('PWA wiring', () => {
    it('PWA-035 once the workout view exists, it holds updates for the length of a workout', () => {
        for (const view of screens(['WorkoutView'])) {
            expect(sources[view], `${view} calls useHoldUpdates() or holdUpdates()`).toMatch(/\b(useHoldUpdates|holdUpdates)\(/);
        }
    });

    it('PWA-003 once onboarding or Today exists, a screen offers the install and start-up listens for Chrome\'s prompt', () => {
        if (screens(['TodayView', 'ReviewStep', 'SetupGateView']).length === 0) return;

        expect(calling('offerInstall'), 'a screen calls offerInstall()').not.toEqual([]);
        expect(main).toMatch(/\blistenForInstall\(\)/);
    });

    it("PWA-003 start-up leaves Chrome's own install prompt alone while no screen offers ours", () => {
        if (calling('offerInstall').length > 0) return;

        // Holding Chrome's prompt with nothing to show it would take the only install path away.
        expect(main).not.toMatch(/\blistenForInstall\(/);
    });
});
