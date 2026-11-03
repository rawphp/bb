import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { read, writeNow } from '@/app/call';
import type { BillingShowOutput } from '@/contracts/billing_show';
import BottomSheet from '@/design/BottomSheet.vue';
import { screenFixture } from '@/testing/schemas';
import PaywallSheet from './PaywallSheet.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), writeNow: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(writeNow);
const trial = screenFixture<BillingShowOutput>('billing_show.empty');

const global = { stubs: { teleport: true } };

describe('PaywallSheet', () => {
    beforeEach(() => {
        readMock.mockReset();
        writeMock.mockReset();
    });

    it('SUB-004 opens at half height, so the screen behind it stays in view', () => {
        const wrapper = mount(PaywallSheet, { global, props: { open: true, payload: trial } });

        expect(wrapper.get('[role="dialog"]').attributes('data-height')).toBe('half');
        expect(wrapper.get('[role="dialog"]').text()).toContain('Unlock your plan');
        expect(wrapper.get('[data-test="checkout"]').text()).toBe('Start 7-day free trial');
        expect(wrapper.find('[data-save]').exists()).toBe(false);
    });

    it('SUB-004 reads the plans when it opens with none given', async () => {
        readMock.mockResolvedValueOnce(trial);
        const wrapper = mount(PaywallSheet, { global, props: { open: false } });
        expect(readMock).not.toHaveBeenCalled();
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);

        await wrapper.setProps({ open: true });
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('billing_show', {}, expect.any(Function), expect.any(Function));
        expect(wrapper.findAll('[role="radio"]')).toHaveLength(3);
    });

    it('SUB-004 Not now closes it', async () => {
        const wrapper = mount(PaywallSheet, { global, props: { open: true, payload: trial } });

        await wrapper.get('[data-cancel]').trigger('click');

        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('SUB-005 cannot be closed while Checkout starts', async () => {
        writeMock.mockReturnValueOnce(new Promise(() => {}));
        const wrapper = mount(PaywallSheet, { global, props: { open: true, payload: trial } });

        await wrapper.get('[data-test="checkout"]').trigger('click');

        const sheet = wrapper.getComponent(BottomSheet);
        expect(sheet.props('cancelDisabled')).toBe(true);
        expect(sheet.props('dismissible')).toBe(false);
    });
});
