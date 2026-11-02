import { createRouter, createWebHistory, type RouteRecordRaw, type RouterHistory } from 'vue-router';
import { trackHistory } from './history';
import { attachNavigation } from './navigation';
import NotFound from './NotFound.vue';
import TabRoot from './TabRoot.vue';
import { TABS, tabPath, HOME_TAB, type TabId } from './tabs';

declare module 'vue-router' {
    interface RouteMeta {
        /** The tab a link straight to this screen opens in. A push joins the active tab. */
        tab?: TabId;
        /** false: rendered on its own, outside the tab bar and stacks (dev screens, sign-in). */
        shell?: boolean;
        /** Width of the edge-swipe-back zone in CSS px (PWA-022). The workout screen uses 16. */
        edgeZone?: number;
        dev?: boolean;
        screen?: string;
        /** false: the screen reads nothing, so /dev/screens renders it without a fixture. */
        fixture?: boolean;
    }
}

type RoutesModule = { default: RouteRecordRaw[] };

// Each feature owns resources/js/features/<feature>/routes.ts, loaded here by glob,
// so a new feature never edits this file. Routes with meta.dev are the dev screens
// and gallery; the server flags them on in local and testing only.
// features/dev is loaded only under the Vite dev server and Vitest, so a production
// `vite build` never emits the screen fixtures or the gallery.
const modules: Record<string, RoutesModule> = {
    ...import.meta.glob<RoutesModule>(['../features/*/routes.ts', '!../features/dev/routes.ts'], { eager: true }),
    ...(import.meta.env.DEV ? import.meta.glob<RoutesModule>('../features/dev/routes.ts', { eager: true }) : {}),
};

export const featureRoutes: RouteRecordRaw[] = Object.keys(modules)
    .sort()
    .flatMap((path) => modules[path]!.default);

export function routesFor(devRoutes: boolean): RouteRecordRaw[] {
    const features = featureRoutes.filter((route) => devRoutes || route.meta?.dev !== true);
    return [
        { path: '/', redirect: tabPath(HOME_TAB) },
        ...features,
        // A placeholder root for each tab whose feature has not declared one yet.
        // It links the tab's screens that take no params, so they stay reachable until then.
        ...TABS.filter((tab) => !featureRoutes.some((route) => route.path === tab.path)).map((tab) => ({
            path: tab.path,
            component: TabRoot,
            props: {
                label: tab.label,
                links: features.filter((route) => route.meta?.tab === tab.id && !route.path.includes(':')).map((route) => route.path),
            },
        })),
        { path: '/:pathMatch(.*)*', component: NotFound },
    ];
}

export function devRoutesEnabled(): boolean {
    return document.querySelector('meta[name="bb-dev-routes"]')?.getAttribute('content') === '1';
}

export function makeRouter(devRoutes = devRoutesEnabled(), history: RouterHistory = createWebHistory()) {
    const tracked = trackHistory(history);
    const router = createRouter({ history: tracked, routes: routesFor(devRoutes) });
    attachNavigation(router, tracked);
    return router;
}
