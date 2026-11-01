import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read } from '@/app/call';
import AccountView from './AccountView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn() }));

const readMock = vi.mocked(read);
const account = { name: 'Sam Lifter', email: 'sam@example.com' };

describe('AccountView', () => {
    beforeEach(() => readMock.mockReset());

    it('CAP-001 renders the payload it is given without calling the server', () => {
        const wrapper = mount(AccountView, { props: { payload: account } });

        expect(wrapper.text()).toContain('Sam Lifter');
        expect(wrapper.text()).toContain('sam@example.com');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('CAP-004 reads account_show when it has no payload', async () => {
        readMock.mockResolvedValueOnce(account);
        const wrapper = mount(AccountView);

        expect(wrapper.text()).toContain('Loading');
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('account_show');
        expect(wrapper.text()).toContain('sam@example.com');
    });

    it.each([
        [new CapabilityError('account_show', 401, null), 'You are signed out.'],
        [new CapabilityError('account_show', 500, null), 'Your account could not be loaded.'],
    ])('CAP-004 says why the account did not load', async (error, message) => {
        readMock.mockRejectedValueOnce(error);
        const wrapper = mount(AccountView);
        await flushPromises();

        expect(wrapper.text()).toContain(message);
    });
});
