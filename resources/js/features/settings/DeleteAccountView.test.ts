import 'fake-indexeddb/auto';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { CapabilityError, writeNow } from '@/app/call';
import { clearReads } from '@/app/cache';
import { entries } from '@/app/outbox';
import { queue, resetOutbox } from '@/testing/outbox';
import { inputViolations } from '@/testing/schemas';
import DeleteAccountView from './DeleteAccountView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), writeNow: vi.fn() }));
vi.mock('@/app/cache', async (original) => ({ ...(await original<typeof import('@/app/cache')>()), clearReads: vi.fn() }));

const writeMock = vi.mocked(writeNow);

async function view() {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:any(.*)*', component: { template: '<p />' } }] });
    await router.push('/settings/delete-account');
    const wrapper = mount(DeleteAccountView, { global: { plugins: [router] } });
    return { wrapper, router };
}

describe('DeleteAccountView', () => {
    beforeEach(async () => {
        writeMock.mockReset();
        vi.mocked(clearReads).mockReset().mockResolvedValue(undefined);
        await resetOutbox();
    });

    it('ACC-005 says what deleting removes, with the button off until DELETE is typed', async () => {
        const { wrapper } = await view();

        expect(wrapper.text()).toContain('It cannot be undone.');
        expect(wrapper.get<HTMLButtonElement>('[data-test="delete"]').element.disabled).toBe(true);

        await wrapper.get('input').setValue('delete');
        expect(wrapper.get<HTMLButtonElement>('[data-test="delete"]').element.disabled).toBe(true);

        await wrapper.get('input').setValue('DELETE');
        expect(wrapper.get<HTMLButtonElement>('[data-test="delete"]').element.disabled).toBe(false);
    });

    it('ACC-005 sends the typed confirmation, forgets the cached reads and leaves', async () => {
        writeMock.mockResolvedValueOnce(undefined);
        const { wrapper, router } = await view();

        await wrapper.get('input').setValue('DELETE');
        await wrapper.get('[data-test="delete"]').trigger('click');
        await flushPromises();

        const [name, input, options] = writeMock.mock.calls[0]!;
        expect(name).toBe('account_delete');
        expect(input).toEqual({ confirmation: 'DELETE' });
        expect(options?.idempotencyKey).toMatch(/^account_delete:/);
        expect(inputViolations('account_delete', input)).toEqual([]);
        expect(clearReads).toHaveBeenCalled();
        expect(router.currentRoute.value.path).toBe('/');
    });

    it("ACC-005 deletes the account's entries left in the outbox, since there is no account to sync them to", async () => {
        await queue({ label: 'Logged a weigh-in', refusal: { status: 422, body: null } });
        writeMock.mockResolvedValueOnce(undefined);
        const { wrapper } = await view();

        await wrapper.get('input').setValue('DELETE');
        await wrapper.get('[data-test="delete"]').trigger('click');
        await flushPromises();

        expect(entries.value).toEqual([]);
    });

    it('CAP-012 shows a refused delete in the server\'s words and stays', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('account_delete', 422, { ok: false, error: { message: 'Type DELETE to confirm.' } }));
        const { wrapper, router } = await view();

        await wrapper.get('input').setValue('DELETE');
        await wrapper.get('[data-test="delete"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('Type DELETE to confirm.');
        expect(router.currentRoute.value.path).toBe('/settings/delete-account');
    });

    async function deleteTyped(wrapper: Awaited<ReturnType<typeof view>>['wrapper']): Promise<void> {
        await wrapper.get('input').setValue('DELETE');
        await wrapper.get('[data-test="delete"]').trigger('click');
        await flushPromises();
    }

    it('ACC-005 clears the cached reads before it leaves the screen', async () => {
        writeMock.mockResolvedValueOnce(undefined);
        const { wrapper, router } = await view();
        let pathWhenCleared = '';
        vi.mocked(clearReads).mockImplementationOnce(async () => {
            pathWhenCleared = router.currentRoute.value.path;
        });

        await deleteTyped(wrapper);

        expect(pathWhenCleared).toBe('/settings/delete-account');
        expect(router.currentRoute.value.path).toBe('/');
    });

    it('ACC-005 still leaves when the cache will not clear, since the gone session clears it on the next read', async () => {
        writeMock.mockResolvedValueOnce(undefined);
        vi.mocked(clearReads).mockRejectedValueOnce(new Error('IndexedDB blocked'));
        const { wrapper, router } = await view();

        await deleteTyped(wrapper);

        expect(router.currentRoute.value.path).toBe('/');
    });

    it.each([401, 419])('ACC-005 a first try refused with %i says the user was signed out and deletes nothing', async (status) => {
        writeMock.mockRejectedValueOnce(new CapabilityError('account_delete', status, { message: 'Unauthenticated.' }));
        const { wrapper, router } = await view();

        await deleteTyped(wrapper);

        expect(wrapper.get('[role="alert"]').text()).toContain('You were signed out, so nothing was deleted.');
        expect(router.currentRoute.value.path).toBe('/settings/delete-account');
    });

    it('CAP-014 takes a new key after a refusal, since the server keeps the refusal under the old one', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('account_delete', 500, { ok: false, error: { code: 'internal' } })).mockResolvedValueOnce(undefined);
        const { wrapper } = await view();

        await deleteTyped(wrapper);
        await wrapper.get('[data-test="delete"]').trigger('click');
        await flushPromises();

        const keys = writeMock.mock.calls.map(([, , options]) => options?.idempotencyKey);
        expect(keys[1]).not.toBe(keys[0]);
    });

    it.each([
        ['no answer', new CapabilityError('account_delete', 0, { reason: 'timeout' }), "No connection, so we can't tell whether your account was deleted."],
        ['a gateway timeout', new CapabilityError('account_delete', 504, null), "can't tell whether your account was deleted"],
        [
            'the first try still running',
            new CapabilityError('account_delete', 409, { ok: false, error: { code: 'conflict', retryable: true, message: 'This change is still being saved. Try again in a moment.' } }),
            'This change is still being saved.',
        ],
    ])('CAP-014 retries after %s with the same key', async (_label, first, said) => {
        writeMock.mockRejectedValueOnce(first).mockResolvedValueOnce(undefined);
        const { wrapper, router } = await view();

        await deleteTyped(wrapper);
        expect(wrapper.get('[role="alert"]').text()).toContain(said);
        expect(clearReads).toHaveBeenCalledTimes(1);
        expect(router.currentRoute.value.path).toBe('/settings/delete-account');

        await wrapper.get('[data-test="delete"]').trigger('click');
        await flushPromises();

        const keys = writeMock.mock.calls.map(([, , options]) => options?.idempotencyKey);
        expect(keys[1]).toBe(keys[0]);
        expect(router.currentRoute.value.path).toBe('/');
    });

    // A try with no answer may never have reached the server (the network failed before
    // it was sent), so a gone session on the retry proves nothing either way.
    it.each([401, 419])('ACC-005 a retry answered %i after an unanswered try says it cannot tell, and stays', async (status) => {
        writeMock
            .mockRejectedValueOnce(new CapabilityError('account_delete', 0, { reason: 'network' }))
            .mockRejectedValueOnce(new CapabilityError('account_delete', status, { message: 'Unauthenticated.' }));
        const { wrapper, router } = await view();

        await deleteTyped(wrapper);
        await wrapper.get('[data-test="delete"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain("can't tell whether your account was deleted. Sign in again to check");
        expect(router.currentRoute.value.path).toBe('/settings/delete-account');
    });
});
