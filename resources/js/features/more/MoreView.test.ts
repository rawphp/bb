import 'fake-indexeddb/auto';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';
import { createMemoryHistory, createRouter, type RouteRecordRaw } from 'vue-router';
import NotFound from '@/app/NotFound.vue';
import { routesFor } from '@/app/router';
import { resetOutbox, queue } from '@/testing/outbox';
import MoreView from './MoreView.vue';

// SET-020's rows in order, then Activity (CAP-022), each with the screen it opens.
const ROWS: [string, string][] = [
    ['Plan and phases', '/plan'],
    ['Adjust phase length', '/plan/current/length'],
    ['End current phase', '/plan/current/end'],
    ['Weigh-ins', '/weigh-ins'],
    ['Custom foods', '/custom-foods'],
    ['Custom exercises', '/exercises'],
    ['Saved meals', '/saved-meals'],
    ['Recipes', '/nutrition/recipes'],
    ['Shopping list', '/shopping'],
    ['Share progress', '/progress'],
    ['Learn', '/learn'],
    ['Settings', '/settings'],
    ['Activity', '/activity'],
];

const stub = { template: '<p />' };

// A router with a screen at each path given, and NotFound for every other path, as the app's has.
function routerWith(paths: string[]) {
    const routes: RouteRecordRaw[] = [
        { path: '/more', component: stub },
        ...paths.map((path) => ({ path, component: stub })),
        { path: '/:pathMatch(.*)*', component: NotFound },
    ];
    return createRouter({ history: createMemoryHistory(), routes });
}

async function mountMore(router = routerWith(ROWS.map(([, path]) => path))) {
    await router.push('/more');
    const wrapper = mount(MoreView, { global: { plugins: [router] } });
    await flushPromises();
    return { wrapper, router };
}

function rows(wrapper: Awaited<ReturnType<typeof mountMore>>['wrapper']) {
    return wrapper.findAll('[data-test="more-row"]').map((row) => [row.text(), row.attributes('href')]);
}

describe('MoreView', () => {
    beforeEach(async () => {
        localStorage.clear();
        await resetOutbox();
    });

    it('SET-020 lists every More row in order, then Activity, each linking its screen', async () => {
        const { wrapper } = await mountMore();

        expect(wrapper.find('h1').text()).toBe('More');
        expect(rows(wrapper)).toEqual(ROWS);
    });

    it('SET-020 leaves out a row until its screen exists, as rp-clone does', async () => {
        const built = ROWS.filter(([title]) => !['Weigh-ins', 'Settings'].includes(title));
        const { wrapper } = await mountMore(routerWith(built.map(([, path]) => path)));

        expect(rows(wrapper)).toEqual(built);
    });

    it("SET-020 with the app's own routes, lists every row whose screen is built, Settings included", async () => {
        const { wrapper } = await mountMore(createRouter({ history: createMemoryHistory(), routes: routesFor(false) }));

        // Weigh-ins waits for its screen (V_WT). Add it here when that route lands.
        expect(rows(wrapper)).toEqual(ROWS.filter(([title]) => title !== 'Weigh-ins'));
    });

    it('SET-020 a tap on a row opens its screen', async () => {
        const { wrapper, router } = await mountMore();

        await wrapper.findAll('[data-test="more-row"]').find((row) => row.text() === 'Learn')!.trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.path).toBe('/learn');
    });

    it('CAP-022 the Activity row opens the list of recent changes', async () => {
        const { wrapper, router } = await mountMore();

        await wrapper.findAll('[data-test="more-row"]').find((row) => row.text() === 'Activity')!.trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.path).toBe('/activity');
    });

    it('ACC-003 ends with the sync line, which counts the changes waiting to sync', async () => {
        await queue({ label: 'Logged 9,000 steps' });
        const { wrapper } = await mountMore();

        expect(wrapper.get('[data-test="sync-line"]').text()).toContain('1 change waiting to sync');
        expect(wrapper.get('main').element.lastElementChild!.contains(wrapper.get('[data-test="sync-line"]').element)).toBe(true);
    });

    it('VIEW-004 shows the gallery under Developer only where the dev routes are on', async () => {
        const dev = await mountMore(createRouter({ history: createMemoryHistory(), routes: routesFor(true) }));
        expect(dev.wrapper.get('[data-test="developer"]').text()).toContain('Gallery');
        expect(dev.wrapper.get('[data-test="developer"] a').attributes('href')).toBe('/dev/gallery');

        const production = await mountMore(createRouter({ history: createMemoryHistory(), routes: routesFor(false) }));
        expect(production.wrapper.find('[data-test="developer"]').exists()).toBe(false);
    });
});
