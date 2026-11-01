import { defineAsyncComponent, type Component } from 'vue';
import type { RouteComponent, RouteRecordRaw } from 'vue-router';

type Lazy = () => Promise<RouteComponent | { default: RouteComponent }>;

// The component a feature route renders for a capability screen (meta.screen).
export function findScreen(name: string, routes: RouteRecordRaw[]): Component | null {
    const route = routes.find((r) => r.meta?.screen === name);
    const component = route && 'component' in route ? route.component : undefined;
    if (!component) return null;

    // SFCs compile to objects; a function here is a lazy import.
    return typeof component === 'function' ? defineAsyncComponent(component as Lazy) : (component as Component);
}
