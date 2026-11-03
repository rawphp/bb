import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read } from '@/app/call';
import type { BillingShowOutput } from '@/contracts/billing_show';
import { toneClasses } from '@/design/tones';
import { screenFixture } from '@/testing/schemas';
import LapsedBannerView from './LapsedBannerView.vue';
import PaywallSheet from './PaywallSheet.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), writeNow: vi.fn() }));

const readMock = vi.mocked(read);
const lapsed = screenFixture<BillingShowOutput>('billing_show.warning');
const subscribed = screenFixture<BillingShowOutput>('billing_show');
const trial = screenFixture<BillingShowOutput>('billing_show.empty');

// The paywall is a BottomSheet, which teleports to <body>; render it in place.
const global = { stubs: { teleport: true } };

describe('LapsedBannerView', () => {
    beforeEach(() => {
        readMock.mockReset();
    });

    it('SUB-010 says the subscription has ended and the data is read-only, in the server\'s words and warning tone', () => {
        const wrapper = mount(LapsedBannerView, { global, props: { payload: lapsed } });
        const banner = wrapper.get('[data-test="lapsed"]');

        expect(banner.text()).toContain('Your subscription has ended. Your data is read-only.');
        expect(banner.get('[data-test="resubscribe"]').text()).toBe('Resubscribe');
        expect(banner.classes()).toEqual(expect.arrayContaining(toneClasses.warning.split(' ')));
        expect(readMock).not.toHaveBeenCalled();
    });

    it.each([
        ['subscribed', subscribed],
        ['never subscribed', trial],
    ])('SUB-010 shows nothing while the subscription has not lapsed (%s)', (_, payload) => {
        const wrapper = mount(LapsedBannerView, { global, props: { payload } });

        expect(wrapper.find('[data-test="lapsed"]').exists()).toBe(false);
        expect(wrapper.text()).toBe('');
    });

    it('SUB-010 Resubscribe opens the paywall over the screen, with the plans it already has', async () => {
        const wrapper = mount(LapsedBannerView, { global, props: { payload: lapsed } });
        expect(wrapper.getComponent(PaywallSheet).props('open')).toBe(false);

        await wrapper.get('[data-test="resubscribe"]').trigger('click');

        expect(wrapper.getComponent(PaywallSheet).props('open')).toBe(true);
        expect(wrapper.get('[role="dialog"]').text()).toContain('Unlock your plan');
        expect(wrapper.get('[data-test="checkout"]').text()).toBe('Subscribe');
        expect(readMock).not.toHaveBeenCalled();
    });

    it('SUB-010 closing the paywall leaves the banner up', async () => {
        const wrapper = mount(LapsedBannerView, { global, props: { payload: lapsed } });

        await wrapper.get('[data-test="resubscribe"]').trigger('click');
        await wrapper.get('[role="dialog"] [data-cancel]').trigger('click');

        expect(wrapper.getComponent(PaywallSheet).props('open')).toBe(false);
        expect(wrapper.find('[data-test="lapsed"]').exists()).toBe(true);
    });

    it('SUB-010 reads billing_show for itself', async () => {
        readMock.mockResolvedValueOnce(lapsed);
        const wrapper = mount(LapsedBannerView, { global });
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('billing_show', {}, expect.any(Function), expect.any(Function));
        expect(wrapper.find('[data-test="lapsed"]').exists()).toBe(true);
    });

    it('PWA-024 shows nothing while it loads, so no tab jumps under a skeleton', () => {
        readMock.mockReturnValueOnce(new Promise(() => {}));
        const wrapper = mount(LapsedBannerView, { global });

        expect(wrapper.find('[data-test="lapsed"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(false);
    });

    it.each([
        ['billing off: the instance has no billing_show', new CapabilityError('billing_show', 404, null)],
        ['the read failed', new CapabilityError('billing_show', 500, null)],
        ['offline', new CapabilityError('billing_show', 0, { reason: 'network' })],
    ])('SUB-020 shows nothing when %s', async (_, failure) => {
        readMock.mockRejectedValueOnce(failure);
        const wrapper = mount(LapsedBannerView, { global });
        await flushPromises();

        expect(wrapper.text()).toBe('');
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    });
});
