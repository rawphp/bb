import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, writeNow } from '@/app/call';
import type { BillingShowOutput } from '@/contracts/billing_show';
import { inputViolations, screenFixture } from '@/testing/schemas';
import PaywallView from './PaywallView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), writeNow: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(writeNow);
const subscribed = screenFixture<BillingShowOutput>('billing_show');
const trial = screenFixture<BillingShowOutput>('billing_show.empty');
const lapsed = screenFixture<BillingShowOutput>('billing_show.warning');
const assign = vi.fn();

function sent(capability: string): unknown {
    const call = writeMock.mock.calls.find(([name]) => name === capability)!;
    expect(inputViolations(capability, call[1])).toEqual([]);
    return call[1];
}

function plan(wrapper: ReturnType<typeof mount>, title: string) {
    return wrapper.findAll('[role="radio"]').find((row) => row.text().startsWith(title))!;
}

describe('PaywallView', () => {
    beforeEach(() => {
        readMock.mockReset();
        writeMock.mockReset();
        assign.mockReset();
        vi.stubGlobal('location', { ...window.location, assign });
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('SUB-003 offers the 7-day free trial to a user who has never subscribed, in the server\'s words', () => {
        const wrapper = mount(PaywallView, { props: { payload: trial } });

        expect(wrapper.text()).toContain('Unlock your plan');
        expect(wrapper.text()).toContain('One subscription unlocks every feature.');
        expect(wrapper.get('[data-test="checkout"]').text()).toBe('Start 7-day free trial');
        expect(wrapper.get('[data-test="terms"]').text()).toBe(trial.terms_text);
        expect(readMock).not.toHaveBeenCalled();
    });

    it('SUB-002 lists each plan on sale with its price, and selects the plan the server picks first', () => {
        const wrapper = mount(PaywallView, { props: { payload: trial } });
        const rows = wrapper.findAll('[role="radio"]');

        expect(rows.map((row) => row.attributes('aria-label'))).toEqual(['Monthly', '6 months', 'Yearly']);
        expect(rows[0]!.text()).toContain('$34.99 per month');
        expect(rows[1]!.text()).toContain('$179.99 per 6 months');
        expect(rows[1]!.text()).toContain('$30.00 a month');
        expect(rows[2]!.text()).toContain('$299.99 per year');
        expect(plan(wrapper, 'Yearly').attributes('aria-checked')).toBe('true');
        expect(plan(wrapper, 'Monthly').attributes('aria-checked')).toBe('false');
    });

    it('SUB-010 asks a lapsed user to subscribe, with no trial', () => {
        const wrapper = mount(PaywallView, { props: { payload: lapsed } });

        expect(wrapper.get('[data-test="checkout"]').text()).toBe('Subscribe');
        expect(wrapper.get('[data-test="terms"]').text()).toBe('Renews automatically until you cancel. Manage or cancel in Settings.');
    });

    it('SUB-005 starts Stripe Checkout for the selected plan and goes there', async () => {
        writeMock.mockResolvedValueOnce({ invalidates: [], url: 'https://checkout.stripe.com/c/pay/test' });
        const wrapper = mount(PaywallView, { props: { payload: subscribed } });

        await wrapper.get('[data-test="checkout"]').trigger('click');
        await flushPromises();

        expect(sent('billing_checkout_start')).toEqual({ plan: 'yearly' });
        expect(assign).toHaveBeenCalledWith('https://checkout.stripe.com/c/pay/test');
    });

    it('SUB-005 starts Checkout for the plan the user picked', async () => {
        writeMock.mockResolvedValueOnce({ invalidates: [], url: 'https://checkout.stripe.com/c/pay/monthly' });
        const wrapper = mount(PaywallView, { props: { payload: trial } });

        await plan(wrapper, 'Monthly').trigger('click');
        expect(plan(wrapper, 'Monthly').attributes('aria-checked')).toBe('true');
        await wrapper.get('[data-test="checkout"]').trigger('click');
        await flushPromises();

        expect(sent('billing_checkout_start')).toEqual({ plan: 'monthly' });
    });

    it('SUB-005 holds the plans and the button while Checkout starts', async () => {
        writeMock.mockReturnValueOnce(new Promise(() => {}));
        const wrapper = mount(PaywallView, { props: { payload: trial } });

        await wrapper.get('[data-test="checkout"]').trigger('click');

        expect(wrapper.get('[data-test="checkout"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-test="checkout"]').attributes('aria-busy')).toBe('true');
        expect(wrapper.findAll('[role="radio"]').every((row) => row.attributes('disabled') !== undefined)).toBe(true);
        expect(wrapper.emitted('busy')).toEqual([[true]]);
        await wrapper.get('[data-test="checkout"]').trigger('click');
        expect(writeMock).toHaveBeenCalledTimes(1);
    });

    it('CAP-012 says why Checkout did not start and lets the user try again', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('billing_checkout_start', 0, { reason: 'network' }));
        const wrapper = mount(PaywallView, { props: { payload: trial } });

        await wrapper.get('[data-test="checkout"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('No connection');
        expect(assign).not.toHaveBeenCalled();
        expect(wrapper.get('[data-test="checkout"]').attributes('disabled')).toBeUndefined();
        expect(wrapper.emitted('busy')).toEqual([[true], [false]]);
    });

    it('CAP-012 shows the server\'s own reason when Checkout is refused', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('billing_checkout_start', 422, { error: { message: 'You already have a subscription. Manage it in Settings.' } }));
        const wrapper = mount(PaywallView, { props: { payload: subscribed } });

        await wrapper.get('[data-test="checkout"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('You already have a subscription. Manage it in Settings.');
    });

    it('SUB-002 offers Try again, and no button to pay, while Stripe gives no plans', async () => {
        readMock.mockResolvedValueOnce(trial);
        const wrapper = mount(PaywallView, { props: { payload: { ...trial, plans: [] } } });

        expect(wrapper.find('[role="radio"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="checkout"]').exists()).toBe(false);
        await wrapper.get('[data-test="retry-plans"]').trigger('click');
        await flushPromises();

        expect(readMock).toHaveBeenCalledWith('billing_show', {}, expect.any(Function), expect.any(Function));
        expect(wrapper.findAll('[role="radio"]')).toHaveLength(3);
    });

    it('PWA-024 shows a skeleton while it loads', () => {
        readMock.mockReturnValueOnce(new Promise(() => {}));
        const wrapper = mount(PaywallView);

        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
        expect(readMock).toHaveBeenCalledWith('billing_show', {}, expect.any(Function), expect.any(Function));
    });

    it('CAP-004 says the plans did not load, and loads them again on Try again', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('billing_show', 500, null)).mockResolvedValueOnce(trial);
        const wrapper = mount(PaywallView);
        await flushPromises();

        expect(wrapper.text()).toContain('The plans could not be loaded.');
        await wrapper.get('[data-test="retry"]').trigger('click');
        await flushPromises();

        expect(wrapper.findAll('[role="radio"]')).toHaveLength(3);
    });
});
