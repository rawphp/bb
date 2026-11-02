import { mount, type VueWrapper } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import AppShell from './AppShell.vue';
import { EDGE_ZONE_PX, SETTLE_MS, startsSwipeBack, swipeGoesBack, swipeIntent } from './edgeSwipe';
import { trackHistory } from './history';
import { SHEET_HOST, TOAST_HOST } from './hosts';
import { KEYPAD } from './keypad';
import { attachNavigation, type Navigation } from './navigation';
import { onScreenReturn } from './screenTop';
import { TABS } from './tabs';

const css = readFileSync(join(import.meta.dirname, 'shell.css'), 'utf8');
const shellHtml = readFileSync(join(import.meta.dirname, '../../views/app.blade.php'), 'utf8');

// The screens that came back on show, by name (screenTop.ts).
const returns: (string | undefined)[] = [];
const Page = defineComponent({
    props: { name: String },
    setup: (props) => {
        onScreenReturn(() => returns.push(props.name));
        return () => h('p', props.name);
    },
});

let wrapper: VueWrapper | undefined;

async function mountShell(slots = {}): Promise<{ wrapper: VueWrapper; router: Router; nav: Navigation }> {
    const history = trackHistory(createMemoryHistory());
    const router = createRouter({
        history,
        routes: [
            ...TABS.map((tab) => ({ path: tab.path, component: Page, props: { name: tab.label } })),
            { path: '/meals/:id', component: Page, props: { name: 'Meal' } },
            { path: '/workouts/:id', component: Page, props: { name: 'Workout' }, meta: { edgeZone: 16 } },
        ],
    });
    const nav = attachNavigation(router, history);
    await router.push('/today');
    await nav.idle();
    wrapper = mount(AppShell, { global: { plugins: [router], stubs: { transition: false, 'transition-group': false } }, slots, attachTo: document.body });
    await nextTick();
    return { wrapper, router, nav };
}

function touch(target: Element, type: string, clientX: number, clientY = 300): Event {
    const event = new Event(type, { bubbles: true, cancelable: true });
    const touches = type === 'touchend' || type === 'touchcancel' ? [] : [{ clientX, clientY }];
    Object.defineProperty(event, 'touches', { value: touches });
    target.dispatchEvent(event);
    return event;
}

const activeStack = (w: VueWrapper) => w.get('.bb-tab-stack--active');
const visibleScreens = (w: VueWrapper) => activeStack(w).findAll('.bb-screen');

describe('AppShell', () => {
    afterEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
        vi.useRealTimers();
    });

    it('NAV-001 shows five tabs, with Today current', async () => {
        const { wrapper } = await mountShell();
        const tabs = wrapper.findAll('nav[aria-label="Tabs"] button');

        expect(tabs.map((t) => t.text())).toEqual(['Today', 'Train', 'Nutrition', 'Progress', 'More']);
        expect(wrapper.get('[aria-current="page"]').text()).toBe('Today');
        expect(activeStack(wrapper).text()).toBe('Today');
    });

    it('NAV-001 switches tab from the tab bar', async () => {
        const { wrapper, nav } = await mountShell();

        await wrapper.get('button[data-tab="progress"]').trigger('click');
        await nav.idle();

        expect(wrapper.get('[aria-current="page"]').text()).toBe('Progress');
        expect(activeStack(wrapper).attributes('data-tab')).toBe('progress');
        expect(wrapper.get('.bb-tab-stack[data-tab="today"]').attributes('aria-hidden')).toBe('true');
    });

    it('NAV-004 has slots for the ActiveWorkoutBar and the Coach button above the tab bar', async () => {
        const { wrapper } = await mountShell({
            'active-workout': '<div data-test="workout-bar">Upper A · 4 of 6 exercises</div>',
            coach: '<button data-test="coach">Coach</button>',
        });
        const above = wrapper.get('.bb-above-tabs');

        expect(above.find('[data-test="workout-bar"]').exists()).toBe(true);
        expect(above.find('.bb-coach-slot [data-test="coach"]').exists()).toBe(true);
        expect(above.element.nextElementSibling?.matches('nav.bb-tab-bar')).toBe(true);
    });

    it('UI-005 UI-010 renders a sheet host and a toast host to teleport into', async () => {
        await mountShell();

        expect(document.querySelector(SHEET_HOST)).not.toBeNull();
        expect(document.querySelector(TOAST_HOST)?.getAttribute('aria-live')).toBe('polite');
    });

    it('PWA-020 keeps a tab\'s screens and their scroll while another tab is shown', async () => {
        const { wrapper, router, nav } = await mountShell();
        await router.push('/meals/1');
        await nextTick();
        const meal = activeStack(wrapper).get('[data-top]').element as HTMLElement;
        meal.scrollTop = 240;

        await nav.select('train');
        await nav.select('today');
        await nextTick();

        expect(activeStack(wrapper).get('[data-top]').element).toBe(meal);
        expect(meal.scrollTop).toBe(240);
        expect(visibleScreens(wrapper).map((s) => s.text())).toEqual(['Today', 'Meal']);
    });

    it('PWA-020 scrolls the root to the top when its tab is tapped again', async () => {
        const { wrapper, nav } = await mountShell();
        const root = activeStack(wrapper).get('[data-top]').element as HTMLElement;
        root.scrollTo = vi.fn();

        await wrapper.get('button[data-tab="today"]').trigger('click');
        await nav.idle();
        await nextTick();

        expect(root.scrollTo).toHaveBeenCalledWith(expect.objectContaining({ top: 0 }));
    });

    it('PWA-021 slides a pushed screen over the previous one, which goes inert', async () => {
        const { wrapper, router } = await mountShell();
        await router.push('/meals/1');
        await nextTick();
        const [below, top] = visibleScreens(wrapper);

        expect(top!.classes()).toContain('bb-slide-enter-active');
        expect(below!.attributes('aria-hidden')).toBe('true');
        expect(top!.attributes('aria-hidden')).toBeUndefined();
    });

    it('PWA-020 tells a screen when it is back on show, after a pop or a tab switch', async () => {
        returns.length = 0;
        const { wrapper, router, nav } = await mountShell();
        await router.push('/meals/1');
        await nextTick();
        expect(returns).toEqual([]);

        const popped = new Promise<void>((resolve) => {
            const off = router.afterEach(() => (off(), resolve()));
        });
        nav.back();
        await popped;
        await nav.idle();
        await nextTick();
        expect(returns).toEqual(['Today']);

        await wrapper.get('button[data-tab="progress"]').trigger('click');
        await nav.idle();
        await wrapper.get('button[data-tab="today"]').trigger('click');
        await nav.idle();
        await nextTick();
        expect(returns).toEqual(['Today', 'Today']);
    });

    it('PWA-021 slides with transforms and cross-fades with reduced motion', () => {
        expect(css).toMatch(/\.bb-slide-enter-from,\s*\.bb-slide-leave-to\s*{\s*transform: translateX\(100%\);/);
        expect(css).toMatch(/transition: transform 280ms/);
        const reduced = css.slice(css.indexOf('@media (prefers-reduced-motion: reduce)'));
        expect(reduced).toMatch(/transition: opacity/);
        expect(reduced).toMatch(/transform: none;\s*opacity: 0;/);
    });

    it('PWA-022 claims a sideways move from the left edge, so the browser\'s own swipe back does not fire', async () => {
        const { wrapper, router } = await mountShell();
        const stack = activeStack(wrapper).element;

        touch(stack, 'touchstart', 5);
        expect(touch(stack, 'touchmove', 40).defaultPrevented).toBe(false);
        touch(stack, 'touchend', 40);

        await router.push('/meals/1');
        await nextTick();
        expect(touch(stack, 'touchstart', 5).defaultPrevented).toBe(false);
        expect(touch(stack, 'touchmove', 40).defaultPrevented).toBe(true);
        touch(stack, 'touchcancel', 40);

        touch(stack, 'touchstart', EDGE_ZONE_PX + 40);
        expect(touch(stack, 'touchmove', EDGE_ZONE_PX + 80).defaultPrevented).toBe(false);
        touch(stack, 'touchcancel', EDGE_ZONE_PX + 80);
    });

    it('PWA-022 leaves a tap or a vertical scroll at the left edge to the screen', async () => {
        const { wrapper, router } = await mountShell();
        await router.push('/meals/1');
        await nextTick();
        vi.useFakeTimers();
        const stack = activeStack(wrapper).element;
        const top = stack.querySelector<HTMLElement>('[data-top]')!;
        const back = vi.spyOn(router, 'back');

        // A tap: the touch is never claimed, so the browser still sends its click.
        expect(touch(stack, 'touchstart', 5).defaultPrevented).toBe(false);
        expect(touch(stack, 'touchend', 5).defaultPrevented).toBe(false);

        // A scroll: once it moves mostly down, the rest of the touch is the screen's.
        touch(stack, 'touchstart', 5, 300);
        expect(touch(stack, 'touchmove', 9, 340).defaultPrevented).toBe(false);
        expect(touch(stack, 'touchmove', 200, 360).defaultPrevented).toBe(false);
        touch(stack, 'touchend', 200);
        vi.advanceTimersByTime(SETTLE_MS);

        expect(top.style.transform).toBe('');
        expect(back).not.toHaveBeenCalled();
    });

    it('PWA-022 UI-020 narrows the edge zone to 16 px on the workout screen', async () => {
        const { wrapper, router } = await mountShell();
        await router.push('/workouts/1');
        await nextTick();
        const stack = activeStack(wrapper).element;

        touch(stack, 'touchstart', 20);
        expect(touch(stack, 'touchmove', 60).defaultPrevented).toBe(false);
        touch(stack, 'touchcancel', 60);
        touch(stack, 'touchstart', 12);
        expect(touch(stack, 'touchmove', 52).defaultPrevented).toBe(true);
        touch(stack, 'touchcancel', 52);
    });

    it('PWA-022 follows the finger and goes back once it is let go far enough', async () => {
        const { wrapper, router } = await mountShell();
        await router.push('/meals/1');
        await nextTick();
        vi.useFakeTimers();
        const stack = activeStack(wrapper).element;
        const top = stack.querySelector<HTMLElement>('[data-top]')!;
        const back = vi.spyOn(router, 'back');

        touch(stack, 'touchstart', 4);
        touch(stack, 'touchmove', 104);
        expect(top.style.transform).toBe('translateX(100px)');

        touch(stack, 'touchmove', 904);
        touch(stack, 'touchend', 904);
        expect(top.style.transform).toBe('translateX(100%)');
        vi.advanceTimersByTime(SETTLE_MS);

        expect(back).toHaveBeenCalledOnce();
    });

    it('PWA-022 settles back in place on a short, slow swipe', async () => {
        const { wrapper, router } = await mountShell();
        await router.push('/meals/1');
        await nextTick();
        vi.useFakeTimers();
        const stack = activeStack(wrapper).element;
        const top = stack.querySelector<HTMLElement>('[data-top]')!;
        const back = vi.spyOn(router, 'back');

        touch(stack, 'touchstart', 4);
        touch(stack, 'touchmove', 40);
        touch(stack, 'touchcancel', 40);
        vi.advanceTimersByTime(SETTLE_MS);

        expect(top.style.transform).toBe('');
        expect(back).not.toHaveBeenCalled();
    });

    it('PWA-022 does not go back if another tab is shown before the swipe settles', async () => {
        const { wrapper, router, nav } = await mountShell();
        await router.push('/meals/1');
        await nextTick();
        const stack = activeStack(wrapper).element;
        const top = stack.querySelector<HTMLElement>('[data-top]')!;

        touch(stack, 'touchstart', 4);
        touch(stack, 'touchmove', 904);
        touch(stack, 'touchend', 904);
        await nav.select('train');
        await router.push('/workouts/1');
        await nextTick();
        const back = vi.spyOn(router, 'back');
        await new Promise((resolve) => setTimeout(resolve, SETTLE_MS + 20));

        expect(back).not.toHaveBeenCalled();
        expect(nav.state.stacks.train.map((s) => s.route.fullPath)).toEqual(['/train', '/workouts/1']);
        expect(top.style.transform).toBe('');
    });

    it('PWA-022 does not go back if the shell goes away before the swipe settles', async () => {
        const { wrapper, router } = await mountShell();
        await router.push('/meals/1');
        await nextTick();
        vi.useFakeTimers();
        const stack = activeStack(wrapper).element;
        const back = vi.spyOn(router, 'back');

        touch(stack, 'touchstart', 4);
        touch(stack, 'touchmove', 904);
        touch(stack, 'touchend', 904);
        wrapper.unmount();
        vi.advanceTimersByTime(SETTLE_MS);

        expect(back).not.toHaveBeenCalled();
    });

    it('PWA-022 goes back past a third of the width or on a flick', () => {
        expect(startsSwipeBack(10, EDGE_ZONE_PX, 1)).toBe(false);
        expect(startsSwipeBack(10, EDGE_ZONE_PX, 2)).toBe(true);
        expect(swipeGoesBack(140, 390, 0.1)).toBe(true);
        expect(swipeGoesBack(80, 390, 0.1)).toBe(false);
        expect(swipeGoesBack(80, 390, 0.9)).toBe(true);
        expect(swipeIntent(5, 5)).toBe('none');
        expect(swipeIntent(12, 4)).toBe('swipe');
        expect(swipeIntent(4, 12)).toBe('scroll');
        expect(swipeIntent(-12, 0)).toBe('scroll');
    });

    it('PWA-010 uses the safe areas on every edge, with viewport-fit=cover', () => {
        expect(shellHtml).toContain('viewport-fit=cover');
        for (const edge of ['top', 'bottom', 'left', 'right']) {
            expect(css).toContain(`env(safe-area-inset-${edge})`);
        }
    });

    it('PWA-011 keeps the page still and lets each screen scroll on its own', () => {
        expect(css).toMatch(/html,\s*body\s*{[^}]*overflow: hidden;[^}]*overscroll-behavior: none;/);
        expect(css).toMatch(/\.bb-screen\s*{[^}]*overflow-y: auto;[^}]*overscroll-behavior: contain;/);
        expect(css).toMatch(/\.bb-bare\s*{[^}]*height: 100%;[^}]*overflow-y: auto;/);
    });

    it('PWA-012 stops selection, callouts and tap flashes on controls, but not in fields or content', () => {
        expect(css).toMatch(/\.bb-shell\s*{[^}]*user-select: none;[^}]*-webkit-touch-callout: none;[^}]*-webkit-tap-highlight-color: transparent;/);
        expect(css).toMatch(/\.bb-selectable,[^{]*input,[^{]*textarea[^{]*{[^}]*user-select: text;/);
    });

    it('PWA-013 UI-030 keeps fields at 16 px or more and pinch zoom on', () => {
        expect(css).toMatch(/input,\s*select,\s*textarea\s*{\s*font-size: max\(16px, 1rem\);/);
        expect(css).toContain('font: -apple-system-body');
        expect(shellHtml).not.toMatch(/user-scalable|maximum-scale/);
    });

    it('PWA-014 opens a decimal keypad for weights and grams, and a number pad for reps and steps', () => {
        expect(KEYPAD.weight.inputmode).toBe('decimal');
        expect(KEYPAD.grams.inputmode).toBe('decimal');
        expect(KEYPAD.reps.inputmode).toBe('numeric');
        expect(KEYPAD.steps.inputmode).toBe('numeric');
    });

    it('PLT-002 centres the phone column up to 480 px', () => {
        expect(css).toMatch(/#app\s*{[^}]*max-width: 480px;[^}]*margin-inline: auto;/);
    });
});
