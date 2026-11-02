import { mount } from '@vue/test-utils';
import type { Component } from 'vue';
import { createMemoryHistory, createRouter } from 'vue-router';

// Tests only: a Today sheet mounted under a plain router, its sheet drawn in place
// (Teleport stubbed) so the wrapper finds what it shows.
export async function mountSheet(component: Component, props: Record<string, unknown> = {}) {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:any(.*)*', component: { template: '<p />' } }] });
    await router.push('/today/2026-09-23/sheet');
    const wrapper = mount(component, { props, global: { plugins: [router], stubs: { teleport: true } } });

    return { wrapper, router };
}
