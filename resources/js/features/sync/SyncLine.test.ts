import 'fake-indexeddb/auto';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { markSynced } from '@/app/outbox';
import SyncLine from './SyncLine.vue';
import { queue, resetOutbox } from '@/testing/outbox';

async function line() {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:any(.*)*', component: { template: '<p />' } }] });
    const wrapper = mount(SyncLine, { global: { plugins: [router] } });
    await flushPromises();
    return { wrapper, router };
}

const refusal = { status: 422, body: { ok: false, error: { message: 'No.' } } };

describe('SyncLine', () => {
    beforeEach(async () => {
        localStorage.clear();
        await resetOutbox();
    });

    afterEach(() => vi.useRealTimers());

    it('ACC-003 says when the device last synced while the outbox is empty', async () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-11-02T09:30:00.000Z'));
        markSynced(Date.now() - 5_000);
        const { wrapper } = await line();
        expect(wrapper.text()).toContain('Synced a few seconds ago');

        markSynced(Date.now() - 3 * 60_000);
        await flushPromises();
        expect(wrapper.text()).toContain('Synced 3 minutes ago');
    });

    it('ACC-003 says so before the device has ever synced', async () => {
        const { wrapper } = await line();

        expect(wrapper.text()).toContain('Not synced yet');
    });

    it('OFF-010 shows the outbox count while it is not empty, refused entries included', async () => {
        await queue({ label: 'Logged 9,000 steps' });
        const { wrapper } = await line();
        expect(wrapper.text()).toContain('1 change waiting to sync');

        await queue({ label: 'Logged a weigh-in', refusal });
        await queue({ label: 'Logged set 3', refusal });
        await flushPromises();
        expect(wrapper.text()).toContain('3 changes waiting to sync');
    });

    it('OFF-010 opens the outbox when tapped', async () => {
        const { wrapper, router } = await line();

        await wrapper.get('a').trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.path).toBe('/sync');
    });
});
