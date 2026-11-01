import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';
import ScreenView from './ScreenView.vue';

// A screen no fixture under tests/fixtures/screens/ will ever exist for.
vi.mock('@/app/router', () => ({
    featureRoutes: [{ path: '/probe', component: defineComponent({ template: '<p />' }), meta: { screen: 'probe_show' } }],
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
});
