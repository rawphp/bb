import { defineAsyncComponent, type Component } from 'vue';
import type { RouteComponent, RouteRecordRaw } from 'vue-router';

type Lazy = () => Promise<RouteComponent | { default: RouteComponent }>;

// Several screens can read one capability; `view` names the route of the one to show.
function screenRoute(name: string, routes: RouteRecordRaw[], view?: string): RouteRecordRaw | undefined {
    return routes.find((r) => r.meta?.screen === name && (view === undefined || r.name === view));
}

// The component a feature route renders for a capability screen (meta.screen).
export function findScreen(name: string, routes: RouteRecordRaw[], view?: string): Component | null {
    const route = screenRoute(name, routes, view);
    const component = route && 'component' in route ? route.component : undefined;
    if (!component) return null;

    // SFCs compile to objects; a function here is a lazy import.
    return typeof component === 'function' ? defineAsyncComponent(component as Lazy) : (component as Component);
}

/** The props the screen's route sets itself (`props: { … }`), so a dev screen opens it as the app does. */
export function routeProps(name: string, routes: RouteRecordRaw[], view?: string): Record<string, unknown> {
    const props = screenRoute(name, routes, view)?.props;
    return props !== null && typeof props === 'object' ? (props as Record<string, unknown>) : {};
}

/** Whether the screen renders from a fixture; one that reads nothing says meta.fixture false. */
export function takesFixture(name: string, routes: RouteRecordRaw[], view?: string): boolean {
    return screenRoute(name, routes, view)?.meta?.fixture !== false;
}
