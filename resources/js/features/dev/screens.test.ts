import { describe, expect, it } from 'vitest';
import { defineComponent } from 'vue';
import { findScreen } from './screens';

describe('findScreen', () => {
    const Eager = defineComponent({ name: 'Eager', template: '<p />' });
    const routes = [
        { path: '/a', component: Eager, meta: { screen: 'a_show' } },
        { path: '/b', component: () => Promise.resolve(Eager), meta: { screen: 'b_show' } },
    ];

    it('CAP-001 finds the component a route renders for a capability screen', () => {
        expect(findScreen('a_show', routes)).toBe(Eager);
        expect(findScreen('b_show', routes)).toMatchObject({ name: 'AsyncComponentWrapper' });
        expect(findScreen('c_show', routes)).toBeNull();
    });

    it('CAP-001 picks one of several screens that read the same capability by route name', () => {
        const List = defineComponent({ name: 'List', template: '<ul />' });
        const shared = [
            { path: '/tab', name: 'tab', component: Eager, meta: { screen: 'a_show' } },
            { path: '/tab/list', name: 'list', component: List, meta: { screen: 'a_show' } },
        ];

        expect(findScreen('a_show', shared)).toBe(Eager);
        expect(findScreen('a_show', shared, 'list')).toBe(List);
        expect(findScreen('a_show', shared, 'nope')).toBeNull();
    });
});
