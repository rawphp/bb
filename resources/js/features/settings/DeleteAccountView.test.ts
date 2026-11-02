import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { CapabilityError, write } from '@/app/call';
import { clearReads } from '@/app/cache';
import { inputViolations } from '@/testing/schemas';
import DeleteAccountView from './DeleteAccountView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), write: vi.fn() }));
vi.mock('@/app/cache', async (original) => ({ ...(await original<typeof import('@/app/cache')>()), clearReads: vi.fn() }));

const writeMock = vi.mocked(write);

async function view() {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:any(.*)*', component: { template: '<p />' } }] });
    await router.push('/settings/delete-account');
    const wrapper = mount(DeleteAccountView, { global: { plugins: [router] } });
    return { wrapper, router };
}

describe('DeleteAccountView', () => {
    beforeEach(() => {
        writeMock.mockReset();
        vi.mocked(clearReads).mockReset().mockResolvedValue(undefined);
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

    it('CAP-014 retries with the same key, and a gone session on the retry means the delete ran', async () => {
        writeMock
            .mockRejectedValueOnce(new CapabilityError('account_delete', 0, { reason: 'timeout' }))
            .mockRejectedValueOnce(new CapabilityError('account_delete', 401, { message: 'Unauthenticated.' }));
        const { wrapper, router } = await view();

        await deleteTyped(wrapper);
        expect(wrapper.get('[role="alert"]').text()).toContain("can't tell whether your account was deleted");
        expect(clearReads).toHaveBeenCalledTimes(1);
        expect(router.currentRoute.value.path).toBe('/settings/delete-account');

        await wrapper.get('[data-test="delete"]').trigger('click');
        await flushPromises();

        const keys = writeMock.mock.calls.map(([, , options]) => options?.idempotencyKey);
        expect(keys[1]).toBe(keys[0]);
        expect(router.currentRoute.value.path).toBe('/');
    });
});
