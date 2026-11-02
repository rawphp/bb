import { flushPromises, mount } from '@vue/test-utils';
import { readdirSync } from 'node:fs';
import { describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, RouterView } from 'vue-router';
import { read } from '@/app/call';
import type { OnboardingShowOutput } from '@/contracts/onboarding_show';
import { screenFixture } from '@/testing/schemas';
import { featureRoutes } from '@/app/router';
import { findScreen, takesFixture } from '../dev/screens';
import routes from './routes';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));

describe('onboarding routes', () => {
    it('VIEW-001 /dev/screens/onboarding_show renders setup from its fixture', () => {
        expect(findScreen('onboarding_show', featureRoutes)).not.toBeNull();
        expect(takesFixture('onboarding_show', featureRoutes)).toBe(true);
    });

    it('VIEW-001 every step of setup opens at /dev/screens from a fixture of its own', () => {
        const states = readdirSync('tests/fixtures/screens')
            .filter((file) => /^onboarding_show(\..+)?\.json$/.test(file))
            .map((file) => file.replace(/\.json$/, ''));
        const shown = new Set(states.map((state) => screenFixture<OnboardingShowOutput>(state).step.key));
        const keys: OnboardingShowOutput['step']['key'][] = [
            'goal',
            'about_you',
            'screening',
            'glp1',
            'body_fat',
            'rate_length',
            'experience',
            'training_days',
            'training_plan',
            'steps',
            'meals',
            'weigh_ins',
            'calories',
            'review',
        ];

        expect([...shown].sort()).toEqual([...keys].sort());
    });

    it('VIEW-001 /dev/screens/intro renders the intro cards with no fixture', () => {
        expect(findScreen('intro', featureRoutes)).not.toBeNull();
        expect(takesFixture('intro', featureRoutes)).toBe(false);
    });

    it('ONB-002 /onboarding opens setup outside the tab bar, reading onboarding_show', async () => {
        vi.mocked(read).mockReturnValueOnce(new Promise(() => {}));
        const router = createRouter({ history: createMemoryHistory(), routes });
        await router.push('/onboarding');
        mount(RouterView, { global: { plugins: [router] } });
        await flushPromises();

        expect(router.currentRoute.value.meta.shell).toBe(false);
        expect(read).toHaveBeenCalledWith('onboarding_show', {}, expect.any(Function), expect.any(Function));
    });

    it('ONB-001 /welcome opens the intro outside the tab bar', async () => {
        const router = createRouter({ history: createMemoryHistory(), routes });
        await router.push('/welcome');

        expect(router.currentRoute.value.meta.shell).toBe(false);
        expect(router.currentRoute.value.matched).toHaveLength(1);
    });
});
