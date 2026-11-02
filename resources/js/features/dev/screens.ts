import { defineAsyncComponent, type Component } from 'vue';
import type { RouteComponent, RouteRecordRaw } from 'vue-router';

type Lazy = () => Promise<RouteComponent | { default: RouteComponent }>;

// The component a feature route renders for a capability screen (meta.screen).
// Several screens can read one capability; `view` names the route of the one to show.
export function findScreen(name: string, routes: RouteRecordRaw[], view?: string): Component | null {
    const route = routes.find((r) => r.meta?.screen === name && (view === undefined || r.name === view));
    const component = route && 'component' in route ? route.component : undefined;
    if (!component) return null;

    // SFCs compile to objects; a function here is a lazy import.
    return typeof component === 'function' ? defineAsyncComponent(component as Lazy) : (component as Component);
}
