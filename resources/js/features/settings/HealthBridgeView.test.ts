import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, writeNow } from '@/app/call';
import type { HealthBridgeShowOutput } from '@/contracts/health_bridge_show';
import type { TokenCreateOutput } from '@/contracts/token_create';
import { inputViolations, screenFixture } from '@/testing/schemas';
import HealthBridgeView from './HealthBridgeView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), writeNow: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(writeNow);
const posted = screenFixture<HealthBridgeShowOutput>('health_bridge_show');
const quiet = screenFixture<HealthBridgeShowOutput>('health_bridge_show.warning');
const notSetUp: HealthBridgeShowOutput = { ...posted, status: { text: 'Not set up', tone: 'neutral' }, token: null, last_post: null };

const created: TokenCreateOutput = {
    invalidates: ['token_list', 'health_bridge_show'],
    token: { id: '01JC0000000000000000000000', name: 'Health bridge', plain_text: '7|s3cr3t-token', scope_text: 'Health bridge', expires_text: 'Expires 3 November 2027' },
};

function sent(capability: string): Record<string, unknown> {
    const call = writeMock.mock.calls.find(([name]) => name === capability)!;
    expect(inputViolations(capability, call[1])).toEqual([]);
    return call[1] as Record<string, unknown>;
}

// The revoke confirmation is a BottomSheet, which teleports to <body>; render it in place.
const global = { stubs: { teleport: true } };

describe('HealthBridgeView', () => {
    beforeEach(() => {
        readMock.mockReset();
        writeMock.mockReset();
    });

    it('INT-001 says what the token can and cannot do, links the shared Shortcut and writes out its steps', () => {
        const wrapper = mount(HealthBridgeView, { global, props: { payload: posted } });

        expect(wrapper.get('[data-test="scope"]').text()).toBe(posted.scope_text);
        expect(wrapper.get('[data-test="shortcut"]').attributes('href')).toBe(posted.shortcut_url);
        expect(wrapper.findAll('[data-test="steps"] li').map((step) => step.text())).toEqual(posted.steps.map((step) => step.text));
        expect(readMock).not.toHaveBeenCalled();
    });

    it('INT-001 shows only the steps when no shared Shortcut is configured', () => {
        const wrapper = mount(HealthBridgeView, { global, props: { payload: { ...posted, shortcut_url: '' } } });

        expect(wrapper.find('[data-test="shortcut"]').exists()).toBe(false);
        expect(wrapper.findAll('[data-test="steps"] li')).toHaveLength(posted.steps.length);
    });

    it('INT-004 shows when the bridge last posted and what that post carried', () => {
        const wrapper = mount(HealthBridgeView, { global, props: { payload: posted } });
        const lastPost = wrapper.get('[data-test="last-post"]').text();

        expect(lastPost).toContain(posted.last_post!.when_text);
        for (const item of posted.last_post!.items) {
            expect(lastPost).toContain(item.label);
            expect(lastPost).toContain(item.value);
        }
        expect(wrapper.text()).toContain(posted.status.text);
    });

    it('INT-004 shows the server\'s warning when the bridge stopped posting', () => {
        const wrapper = mount(HealthBridgeView, { global, props: { payload: quiet } });

        expect(wrapper.text()).toContain('No posts for 3 days. Check the automation is still on.');
    });

    it('INT-001 shows the token, without its secret, with its expiry and when it was last used', () => {
        const wrapper = mount(HealthBridgeView, { global, props: { payload: posted } });
        const token = wrapper.get('[data-test="token"]').text();

        for (const text of [posted.token!.name, posted.token!.expires_text, posted.token!.last_used_text]) {
            expect(token).toContain(text);
        }
        expect(wrapper.find('[data-test="set-up"]').exists()).toBe(false);
    });

    it('INT-001 sets up a Health bridge token and shows it once', async () => {
        writeMock.mockResolvedValueOnce(created);
        readMock.mockResolvedValueOnce(posted);
        const wrapper = mount(HealthBridgeView, { global, props: { payload: notSetUp } });

        expect(wrapper.find('[data-test="last-post"]').exists()).toBe(false);
        await wrapper.get('[data-test="set-up"]').trigger('click');
        await flushPromises();

        expect(sent('token_create')).toEqual({ id: expect.any(String), name: 'Health bridge', expires_in_days: 365, scope: 'health_bridge', capabilities: [] });
        expect(wrapper.get('[data-test="plain-text"]').text()).toBe('7|s3cr3t-token');

        await wrapper.get('[data-test="done"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-test="plain-text"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('7|s3cr3t-token');
        expect(readMock).toHaveBeenCalledWith('health_bridge_show', {}, expect.any(Function), expect.any(Function));
        expect(wrapper.get('[data-test="token"]').text()).toContain(posted.token!.name);
    });

    it('INT-001 retries a set-up that got no answer as the same create, so it never makes two tokens', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('token_create', 0, { reason: 'timeout' })).mockResolvedValueOnce(created);
        const wrapper = mount(HealthBridgeView, { global, props: { payload: notSetUp } });

        await wrapper.get('[data-test="set-up"]').trigger('click');
        await flushPromises();
        await wrapper.get('[data-test="set-up"]').trigger('click');
        await flushPromises();

        const [first, second] = writeMock.mock.calls;
        expect(second![1]).toEqual(first![1]);
        expect(second![2]).toEqual(first![2]);
        expect(wrapper.get('[data-test="plain-text"]').text()).toBe('7|s3cr3t-token');
    });

    it('INT-001 says so when a token was made but its secret was lost', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('token_create', 409, { ok: false, error: { message: 'This token already exists.' } }));
        readMock.mockResolvedValueOnce(posted);
        const wrapper = mount(HealthBridgeView, { global, props: { payload: notSetUp } });

        await wrapper.get('[data-test="set-up"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-test="notice"]').text()).toContain("can't be shown again");
        expect(wrapper.find('[data-test="token"]').exists()).toBe(true);
    });

    it('INT-001 shows the server\'s message when the set-up is refused', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('token_create', 403, { ok: false, error: { message: 'Only you can make a token.' } }));
        const wrapper = mount(HealthBridgeView, { global, props: { payload: notSetUp } });

        await wrapper.get('[data-test="set-up"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('Only you can make a token.');
    });

    it('INT-001 revokes the bridge token after the user confirms', async () => {
        writeMock.mockResolvedValueOnce({ invalidates: ['token_list', 'health_bridge_show'] });
        readMock.mockResolvedValueOnce(notSetUp);
        const wrapper = mount(HealthBridgeView, { global, props: { payload: posted } });

        await wrapper.get('[data-test="revoke"]').trigger('click');
        expect(writeMock).not.toHaveBeenCalled();
        await wrapper.get('[role="dialog"] [data-save]').trigger('click');
        await flushPromises();

        expect(sent('token_revoke')).toEqual({ id: posted.token!.id });
        expect(wrapper.find('[data-test="token"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="set-up"]').exists()).toBe(true);
    });
});
