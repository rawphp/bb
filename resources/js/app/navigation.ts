import { markRaw, reactive } from 'vue';
import { useRouter, type RouteLocationNormalizedLoaded, type Router } from 'vue-router';
import type { TrackedHistory } from './history';
import { HOME_TAB, TABS, tabAtRoot, tabPath, type TabId } from './tabs';

export interface Screen {
    key: number;
    route: RouteLocationNormalizedLoaded;
}

export interface NavigationState {
    active: TabId;
    stacks: Record<TabId, Screen[]>;
    /** Whether the last stack change slides (PWA-021). Tab switches and finished swipes don't. */
    animate: boolean;
    /** Bumped when the active tab is tapped at its root: scroll it to the top (PWA-020). */
    scrollToTop: number;
}

export interface Navigation {
    readonly state: NavigationState;
    /** A tab bar tap: switch tab, or go to the root, or scroll to the top (PWA-020). */
    select(tab: TabId): Promise<void>;
    /** In-app back. It goes through the browser's history, so both stay one stack (PWA-025). */
    back(options?: { animate?: boolean }): void;
    /** Resolves once any tab switch or landing has finished rewriting the history. */
    idle(): Promise<void>;
}

// How long to wait for the browser to answer history.go() before carrying on.
const GO_TIMEOUT_MS = 1000;

/**
 * One stack per tab (PWA-020), kept in step with the browser's history so the
 * browser's back, Android's system back and the in-app back all pop the same
 * stack (PWA-025). The history always holds exactly the active tab's stack:
 * a tab switch goes back to the first entry and replays the other tab's stack.
 *
 * A pushed screen joins the active tab, whatever tab its route names, so one
 * screen is reachable from two tabs (NAV-002). A route's meta.tab only picks the
 * tab when a link opens the app straight into that screen.
 */
export function createNavigation(router: Router, history: TrackedHistory): Navigation {
    const state = reactive<NavigationState>({
        active: HOME_TAB,
        stacks: Object.fromEntries(TABS.map((tab) => [tab.id, []])) as unknown as Record<TabId, Screen[]>,
        animate: true,
        scrollToTop: 0,
    });

    let key = 0;
    let syncing = false;
    let instantBack = false;
    let settle: (() => void) | undefined;
    let queue: Promise<void> = Promise.resolve();

    // The route stays raw: a reactive copy would proxy its components for RouterView.
    const screen = (route: RouteLocationNormalizedLoaded): Screen => ({ key: ++key, route: markRaw(route) });
    const stack = (): Screen[] => state.stacks[state.active];
    const current = (): RouteLocationNormalizedLoaded => router.currentRoute.value;

    function enqueue(work: () => Promise<void>): Promise<void> {
        queue = queue.then(work).catch((error: unknown) => console.error(error));
        return queue;
    }

    async function rewriting(work: () => Promise<void>): Promise<void> {
        syncing = true;
        try {
            await work();
        } finally {
            syncing = false;
        }
    }

    async function go(delta: number): Promise<void> {
        const done = new Promise<void>((resolve) => (settle = resolve));
        router.go(delta);
        await Promise.race([done, new Promise((resolve) => setTimeout(resolve, GO_TIMEOUT_MS))]);
        settle = undefined;
    }

    // Opens the app (or comes back from a screen outside the shell) at `to`.
    // A screen below a tab's root gets the root under it, so back has somewhere to go.
    async function land(to: RouteLocationNormalizedLoaded): Promise<void> {
        const root = tabAtRoot(to.path);
        const tab = root ?? to.meta.tab ?? state.active;
        state.animate = false;
        state.active = tab;
        if (root) {
            state.stacks[tab] = [screen(to)];
            return;
        }
        const target = to.fullPath;
        let below = to;
        await rewriting(async () => {
            await router.replace(tabPath(tab));
            below = current();
            await router.push(target);
        });
        state.stacks[tab] = [screen(below), screen(current())];
    }

    async function switchTo(tab: TabId): Promise<void> {
        const leaving = stack();
        state.animate = false;
        state.active = tab;
        await rewriting(async () => {
            if (leaving.length > 1) await go(-(leaving.length - 1));
            const arriving = state.stacks[tab];
            if (arriving.length === 0) {
                await router.replace(tabPath(tab));
                state.stacks[tab] = [screen(current())];
                return;
            }
            await router.replace(arriving[0]!.route.fullPath);
            for (const entry of arriving.slice(1)) await router.push(entry.route.fullPath);
        });
    }

    async function popToRoot(): Promise<void> {
        const popping = stack();
        const depth = popping.length;
        state.animate = true;
        popping.splice(1);
        await rewriting(() => go(-(depth - 1)));
    }

    // A root screen that replaced itself with another path (Today on a picked day) is not the
    // tab's root, so a tap goes there first.
    async function replaceWithRoot(tab: TabId): Promise<void> {
        state.animate = false;
        await rewriting(() => router.replace(tabPath(tab)).then(() => undefined));
        state.stacks[tab] = [screen(current())];
    }

    function select(tab: TabId): Promise<void> {
        return enqueue(async () => {
            if (tab !== state.active) return switchTo(tab);
            if (stack().length > 1) return popToRoot();
            const root = stack()[0];
            if (root && root.route.path !== tabPath(tab)) return replaceWithRoot(tab);
            state.scrollToTop++;
        });
    }

    // A link to a tab's root (a RouterLink, or a pushed path) is a tab bar tap.
    // From a screen outside the shell it is not: afterEach lands it instead.
    router.beforeEach((to, from) => {
        if (syncing || from.matched.length === 0 || from.meta.shell === false || history.peekOp()?.type === 'pop') return true;
        const tab = tabAtRoot(to.path);
        if (tab === undefined) return true;
        void select(tab);
        return false;
    });

    router.afterEach((to, from, failure) => {
        const op = history.takeOp();
        if (settle) settle();
        if (failure || syncing || to.meta.shell === false) return;

        const top = stack().at(-1);
        if (from.matched.length === 0 || from.meta.shell === false) {
            if (top?.route.fullPath !== to.fullPath) void enqueue(() => land(to));
            return;
        }

        const entries = stack();
        state.animate = true;
        if (op?.type === 'pop' && op.delta < 0) {
            const remaining = entries.length + op.delta;
            if (remaining >= 1 && entries[remaining - 1]!.route.fullPath === to.fullPath) {
                state.animate = !instantBack;
                entries.splice(remaining);
            } else {
                // A history entry from before a reload: start that screen afresh.
                void enqueue(() => land(to));
            }
        } else if (op?.type === 'replace') {
            // A replace that keeps the path only changes the address of the screen on show
            // (a query it has used up, say), so that screen stays; another path is a new screen.
            const last = entries.at(-1);
            entries.splice(-1, 1, last?.route.path === to.path ? { key: last.key, route: markRaw(to) } : screen(to));
        } else {
            entries.push(screen(to));
        }
        instantBack = false;
    });

    return {
        state,
        select,
        back(options = {}) {
            if (stack().length < 2) return;
            instantBack = options.animate === false;
            router.back();
        },
        idle: () => queue,
    };
}

const navigations = new WeakMap<Router, Navigation>();

export function attachNavigation(router: Router, history: TrackedHistory): Navigation {
    const navigation = createNavigation(router, history);
    navigations.set(router, navigation);
    return navigation;
}

/** The router's navigation, if makeRouter() made it; a screen rendered outside the shell has none. */
export function findNavigation(router: Router): Navigation | undefined {
    return navigations.get(router);
}

export function useNavigation(): Navigation {
    const navigation = navigations.get(useRouter());
    if (!navigation) throw new Error('The router was not made by makeRouter(), so it has no navigation.');
    return navigation;
}
