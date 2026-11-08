import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import type { Navigation, NavigationState } from './navigation';
import { TABS, type TabId } from './tabs';
import TabBar from './TabBar.vue';

const nav = vi.hoisted(() => ({ current: undefined as unknown as Navigation }));
vi.mock('./navigation', () => ({ useNavigation: () => nav.current }));

function mountBar(depth: number) {
    const stacks = Object.fromEntries(TABS.map((tab) => [tab.id, []])) as unknown as NavigationState['stacks'];
    const state = reactive<NavigationState>({ active: 'today', stacks, animate: true, scrollToTop: 0 });
    state.stacks.today = Array.from({ length: depth }, (_, key) => ({ key, route: {} as never }));
    state.stacks.train = [{ key: 99, route: {} as never }];
    const back = vi.fn();
    nav.current = { state, back, select: vi.fn(), idle: () => Promise.resolve() };
    return { wrapper: mount(TabBar), state, back };
}

describe('TabBar', () => {
    it('NAV-003 shows no Back button while the tab is at its root', () => {
        const { wrapper } = mountBar(1);

        expect(wrapper.find('[data-back]').exists()).toBe(false);
    });

    it('NAV-003 shows a Back button above the tabs while the tab has a screen to go back to', () => {
        const { wrapper } = mountBar(2);

        const back = wrapper.get('button[data-back]');
        expect(back.attributes('aria-label')).toBe('Back');
        expect(back.text()).toBe('Back');
        expect(back.find('svg.bb-tab-icon').exists()).toBe(true);
        expect(wrapper.get('nav').element.firstElementChild).toBe(back.element);
    });

    it('NAV-003 goes back once on a click, as the edge swipe does (PWA-022, PWA-025)', async () => {
        const { wrapper, back } = mountBar(2);

        await wrapper.get('[data-back]').trigger('click');

        expect(back).toHaveBeenCalledTimes(1);
    });

    it('NAV-003 hides the Back button on switching to a tab at its root', async () => {
        const { wrapper, state } = mountBar(2);

        state.active = 'train' satisfies TabId;
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-back]').exists()).toBe(false);
    });
});
