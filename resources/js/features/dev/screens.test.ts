import { describe, expect, it } from 'vitest';
import { defineComponent } from 'vue';
import { findScreen, takesFixture } from './screens';

describe('findScreen', () => {
    const Eager = defineComponent({ name: 'Eager', template: '<p />' });
    const routes = [
        { path: '/a', component: Eager, meta: { screen: 'a_show' } },
        { path: '/b', component: () => Promise.resolve(Eager), meta: { screen: 'b_show' } },
        { path: '/c', component: Eager, meta: { screen: 'c_delete', fixture: false } },
    ];

    it('CAP-001 finds the component a route renders for a capability screen', () => {
        expect(findScreen('a_show', routes)).toBe(Eager);
        expect(findScreen('b_show', routes)).toMatchObject({ name: 'AsyncComponentWrapper' });
        expect(findScreen('c_show', routes)).toBeNull();
    });

    it('CAP-001 knows a screen that reads nothing takes no fixture', () => {
        expect(takesFixture('a_show', routes)).toBe(true);
        expect(takesFixture('c_delete', routes)).toBe(false);
    });
});
