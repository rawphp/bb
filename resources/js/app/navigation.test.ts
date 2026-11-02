import { describe, expect, it } from 'vitest';
import { defineComponent } from 'vue';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import { trackHistory } from './history';
import { createNavigation, type Navigation } from './navigation';
import { TABS, type TabId } from './tabs';

const Screen = defineComponent({ render: () => null });

function setup(start = '/today'): { router: Router; nav: Navigation; ready: Promise<void> } {
    const history = trackHistory(createMemoryHistory());
    const router = createRouter({
        history,
        routes: [
            { path: '/', redirect: '/today' },
            ...TABS.map((tab) => ({ path: tab.path, component: Screen })),
            { path: '/meals/:id', component: Screen, meta: { tab: 'nutrition' as const } },
            { path: '/workouts/:id', component: Screen, meta: { tab: 'train' as const } },
            { path: '/dev/gallery', component: Screen, meta: { shell: false } },
        ],
    });
    const nav = createNavigation(router, history);
    const ready = router.push(start).then(() => nav.idle());

    return { router, nav, ready };
}

function navigated(router: Router): Promise<void> {
    return new Promise((resolve) => {
        const off = router.afterEach(() => {
            off();
            resolve();
        });
    });
}

async function back(router: Router, nav: Navigation): Promise<void> {
    const done = navigated(router);
    router.back();
    await done;
    await nav.idle();
}

const paths = (nav: Navigation, tab: TabId = nav.state.active) => nav.state.stacks[tab].map((s) => s.route.fullPath);

describe('navigation', () => {
    it('NAV-001 opens on the Today tab', async () => {
        const { router, nav, ready } = setup('/');
        await ready;

        expect(nav.state.active).toBe('today');
        expect(paths(nav)).toEqual(['/today']);
        expect(router.currentRoute.value.fullPath).toBe('/today');
    });

    it('PWA-020 keeps each tab\'s stack when switching tabs', async () => {
        const { router, nav, ready } = setup();
        await ready;

        await router.push('/meals/1');
        await nav.select('train');
        await router.push('/workouts/7');
        await nav.select('today');

        expect(nav.state.active).toBe('today');
        expect(paths(nav, 'today')).toEqual(['/today', '/meals/1']);
        expect(paths(nav, 'train')).toEqual(['/train', '/workouts/7']);
        expect(router.currentRoute.value.fullPath).toBe('/meals/1');
    });

    it('PWA-020 pops to the root when the active tab is tapped, then asks to scroll to the top', async () => {
        const { router, nav, ready } = setup();
        await ready;
        await router.push('/meals/1');
        await router.push('/meals/2');

        await nav.select('today');
        expect(paths(nav)).toEqual(['/today']);
        expect(router.currentRoute.value.fullPath).toBe('/today');
        expect(nav.state.scrollToTop).toBe(0);

        await nav.select('today');
        expect(nav.state.scrollToTop).toBe(1);
    });

    it('PWA-025 pops the active stack on browser back', async () => {
        const { router, nav, ready } = setup();
        await ready;
        await router.push('/meals/1');
        await router.push('/meals/2');

        await back(router, nav);

        expect(paths(nav)).toEqual(['/today', '/meals/1']);
        expect(router.currentRoute.value.fullPath).toBe('/meals/1');
    });

    it('PWA-025 walks the new tab\'s stack on browser back after a tab switch', async () => {
        const { router, nav, ready } = setup();
        await ready;
        await router.push('/meals/1');
        await nav.select('nutrition');
        await router.push('/meals/2');

        await back(router, nav);

        expect(nav.state.active).toBe('nutrition');
        expect(paths(nav)).toEqual(['/nutrition']);
        expect(router.currentRoute.value.fullPath).toBe('/nutrition');
        expect(paths(nav, 'today')).toEqual(['/today', '/meals/1']);
    });

    it('PWA-025 replaces the top screen on a router replace', async () => {
        const { router, nav, ready } = setup();
        await ready;
        await router.push('/meals/1');

        await router.replace('/meals/2');

        expect(paths(nav)).toEqual(['/today', '/meals/2']);
    });

    it('PWA-025 opens a link straight into its screen, over its tab\'s root', async () => {
        const { router, nav, ready } = setup('/meals/5');
        await ready;

        expect(nav.state.active).toBe('nutrition');
        expect(paths(nav)).toEqual(['/nutrition', '/meals/5']);

        await back(router, nav);
        expect(router.currentRoute.value.fullPath).toBe('/nutrition');
        expect(paths(nav)).toEqual(['/nutrition']);
    });

    it('NAV-002 reaches the same screen from two tabs, each in its own stack', async () => {
        const { router, nav, ready } = setup();
        await ready;

        await router.push('/meals/1');
        await nav.select('nutrition');
        await router.push('/meals/1');

        expect(paths(nav, 'today')).toEqual(['/today', '/meals/1']);
        expect(paths(nav, 'nutrition')).toEqual(['/nutrition', '/meals/1']);
    });

    it('PWA-020 switches tab when a screen links to another tab\'s root', async () => {
        const { router, nav, ready } = setup();
        await ready;
        await router.push('/meals/1');

        await router.push('/progress').catch(() => undefined);
        await nav.idle();

        expect(nav.state.active).toBe('progress');
        expect(paths(nav, 'today')).toEqual(['/today', '/meals/1']);
        expect(router.currentRoute.value.fullPath).toBe('/progress');
    });

    it('PWA-022 goes back without a slide when the swipe already moved the screen', async () => {
        const { router, nav, ready } = setup();
        await ready;
        await router.push('/meals/1');
        expect(nav.state.animate).toBe(true);

        const done = navigated(router);
        nav.back({ animate: false });
        await done;

        expect(paths(nav)).toEqual(['/today']);
        expect(nav.state.animate).toBe(false);
    });

    it('PWA-020 does nothing on back at a tab\'s root', async () => {
        const { router, nav, ready } = setup();
        await ready;

        nav.back();
        await nav.idle();

        expect(paths(nav)).toEqual(['/today']);
        expect(router.currentRoute.value.fullPath).toBe('/today');
    });

    it('PWA-025 leaves the stacks alone for a screen outside the shell', async () => {
        const { router, nav, ready } = setup();
        await ready;

        await router.push('/dev/gallery');

        expect(paths(nav)).toEqual(['/today']);
    });
});
