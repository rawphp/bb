import { createRouter, createWebHistory, type RouteRecordRaw, type RouterHistory } from 'vue-router';
import NotFound from './NotFound.vue';

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
    return [
        // SHELL_NAV replaces this with the tab bar's home.
        { path: '/', redirect: '/account' },
        ...featureRoutes.filter((route) => devRoutes || route.meta?.dev !== true),
        { path: '/:pathMatch(.*)*', component: NotFound },
    ];
}

export function devRoutesEnabled(): boolean {
    return document.querySelector('meta[name="bb-dev-routes"]')?.getAttribute('content') === '1';
}

export function makeRouter(devRoutes = devRoutesEnabled(), history: RouterHistory = createWebHistory()) {
    return createRouter({ history, routes: routesFor(devRoutes) });
}
