import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';
import ScreenView from './ScreenView.vue';

// A screen no fixture under tests/fixtures/screens/ will ever exist for.
vi.mock('@/app/router', () => ({
    featureRoutes: [
        { path: '/probe', component: defineComponent({ template: '<p />' }), meta: { screen: 'probe_show' } },
        { path: '/form', component: defineComponent({ props: ['payload'], template: '<p>form {{ payload === undefined }}</p>' }), meta: { screen: 'form', fixture: false } },
        { path: '/act', name: 'act', component: defineComponent({ props: ['action'], template: '<p>act {{ action }}</p>' }), props: { action: 'end' }, meta: { screen: 'form', fixture: false } },
    ],
}));

describe('ScreenView', () => {
    it('CAP-001 says when no feature route renders the named screen', async () => {
        const wrapper = mount(ScreenView, { props: { name: 'nope_show' } });
        await flushPromises();

        expect(wrapper.text()).toBe('No screen renders nope_show.');
    });

    it('CAP-001 says where the fixture goes when a screen has none yet', async () => {
        const wrapper = mount(ScreenView, { props: { name: 'probe_show' } });
        await flushPromises();

        expect(wrapper.text()).toBe('No fixture at tests/fixtures/screens/probe_show.json.');
    });

    it('CAP-001 renders a <name>.<state> fixture with the screen for <name>', async () => {
        const wrapper = mount(ScreenView, { props: { name: 'probe_show.empty' } });
        await flushPromises();

        expect(wrapper.text()).toBe('No fixture at tests/fixtures/screens/probe_show.empty.json.');
    });

    it('CAP-001 renders a screen that reads nothing without a fixture', async () => {
        const wrapper = mount(ScreenView, { props: { name: 'form' } });
        await flushPromises();

        expect(wrapper.text()).toBe('form true');
    });

    it('CAP-001 gives a screen the props its route sets, so ?view=<name> opens that route as the app does', async () => {
        const wrapper = mount(ScreenView, { props: { name: 'form', view: 'act' } });
        await flushPromises();

        expect(wrapper.text()).toBe('act end');
    });
});
