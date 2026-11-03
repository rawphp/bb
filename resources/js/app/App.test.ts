// The shell's Coach button reads agent_status_show through the IndexedDB cache.
import 'fake-indexeddb/auto';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import { createMemoryHistory, createRouter } from 'vue-router';
import App from './App.vue';
import { trackHistory } from './history';
import { attachNavigation } from './navigation';
import { TABS } from './tabs';

const Page = defineComponent({ props: { name: String }, setup: (props) => () => h('p', props.name) });

let unmount: (() => void) | undefined;

async function mountAt(path: string) {
    const history = trackHistory(createMemoryHistory());
    const router = createRouter({
        history,
        routes: [
            ...TABS.map((tab) => ({ path: tab.path, component: Page, props: { name: tab.label } })),
            { path: '/dev/gallery', component: Page, props: { name: 'Gallery' }, meta: { shell: false } },
        ],
    });
    const nav = attachNavigation(router, history);
    await router.push(path);
    await nav.idle();
    const wrapper = mount(App, { global: { plugins: [router] } });
    unmount = () => wrapper.unmount();
    await nextTick();
    return wrapper;
}

describe('App', () => {
    afterEach(() => unmount?.());

    it('PWA-020 renders a tab\'s screen inside the shell', async () => {
        const wrapper = await mountAt('/today');

        expect(wrapper.find('.bb-shell').exists()).toBe(true);
        expect(wrapper.find('.bb-bare').exists()).toBe(false);
    });

    it('PWA-011 renders a screen outside the shell on its own, in a box that scrolls', async () => {
        const wrapper = await mountAt('/dev/gallery');

        expect(wrapper.find('.bb-shell').exists()).toBe(false);
        expect(wrapper.get('.bb-bare').text()).toBe('Gallery');
    });
});
