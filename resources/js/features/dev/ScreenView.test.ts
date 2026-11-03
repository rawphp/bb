import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';
import type { RouteLocationNormalized } from 'vue-router';
import routes from './routes';
import ScreenView from './ScreenView.vue';

// A screen no fixture under tests/fixtures/screens/ will ever exist for.
vi.mock('@/app/router', () => ({
    featureRoutes: [
        { path: '/probe', component: defineComponent({ template: '<p />' }), meta: { screen: 'probe_show' } },
        { path: '/meal', component: defineComponent({ props: ['payload', 'mealId'], template: '<p>{{ mealId }} {{ payload !== undefined }}</p>' }), meta: { screen: 'food_search' } },
        { path: '/form', component: defineComponent({ props: ['payload'], template: '<p>form {{ payload === undefined }}</p>' }), meta: { screen: 'form', fixture: false } },
        { path: '/two', component: defineComponent({ props: ['payload', 'week'], template: '<p>{{ payload.date }} {{ week.days[0].calories_text }}</p>' }), meta: { screen: 'today_show', fixtures: { week: 'week_show' } } },
        { path: '/meal-week', component: defineComponent({ props: ['payload', 'week'], template: '<p>{{ week.days[0].calories_text }}</p>' }), meta: { screen: 'meal_show', fixtures: { week: 'week_show' } } },
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

    it('CAP-001 passes the screen its props from the query, beside the fixture', async () => {
        const wrapper = mount(ScreenView, { props: { name: 'food_search', screenProps: { mealId: '01JBX5M0000000000000000001' } } });

        // The fixture is a dynamic import.
        await vi.waitFor(() => expect(wrapper.text()).toBe('01JBX5M0000000000000000001 true'));
    });

    it('CAP-001 /dev/screens/<name> turns every query key but view into a screen prop', () => {
        const props = routes[0]!.props as (route: Partial<RouteLocationNormalized>) => Record<string, unknown>;

        expect(props({ params: { name: 'food_search' }, query: { view: 'pantry', mealId: 'm1', pending: ['a', 'b'] } })).toEqual({
            name: 'food_search',
            view: 'pantry',
            screenProps: { mealId: 'm1' },
        });
    });

    it('VIEW-001 gives a screen that shows two reads the fixture of the other one, in the same state', async () => {
        const wrapper = mount(ScreenView, { props: { name: 'today_show.empty' } });

        await vi.waitFor(() => expect(wrapper.text()).toBe('2026-09-19 –'));
    });

    it('VIEW-001 falls back to the other read\'s main fixture when it has none in that state', async () => {
        const wrapper = mount(ScreenView, { props: { name: 'meal_show.short' } });

        await vi.waitFor(() => expect(wrapper.text()).toBe('2200'));
    });
});
