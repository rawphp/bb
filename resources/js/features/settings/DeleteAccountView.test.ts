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

        const [name, input] = writeMock.mock.calls[0]!;
        expect(name).toBe('account_delete');
        expect(input).toEqual({ confirmation: 'DELETE' });
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
});
