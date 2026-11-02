import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, write } from '@/app/call';
import type { TokenCreateOutput } from '@/contracts/token_create';
import type { TokenListOutput } from '@/contracts/token_list';
import { inputViolations, screenFixture } from '@/testing/schemas';
import TokensView from './TokensView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), write: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(write);
const content = screenFixture<TokenListOutput>('token_list');
const empty = screenFixture<TokenListOutput>('token_list.empty');
const expiring = screenFixture<TokenListOutput>('token_list.warning');

const created: TokenCreateOutput = {
    invalidates: ['token_list'],
    token: { id: '01JC0000000000000000000000', name: 'Claude Desktop', plain_text: '7|s3cr3t-token', scope_text: 'Read only', expires_text: 'Expires 31 January 2027' },
};

function sent(capability: string): Record<string, unknown> {
    const call = writeMock.mock.calls.find(([name]) => name === capability)!;
    expect(inputViolations(capability, call[1])).toEqual([]);
    return call[1] as Record<string, unknown>;
}

describe('TokensView', () => {
    beforeEach(() => {
        readMock.mockReset();
        writeMock.mockReset();
    });

    it('CAP-021 lists each token with its scope, expiry and when it was last used', () => {
        const wrapper = mount(TokensView, { props: { payload: content } });

        for (const token of content.tokens) {
            for (const text of [token.name, token.scope_text, token.expires_text, token.last_used_text, token.status.text]) {
                expect(wrapper.text()).toContain(text);
            }
        }
        expect(readMock).not.toHaveBeenCalled();
    });

    it('CAP-021 shows the server\'s warning for a token about to expire', () => {
        const wrapper = mount(TokensView, { props: { payload: expiring } });

        expect(wrapper.text()).toContain('Expires in 3 days');
    });

    it('CAP-021 says there are no tokens yet', () => {
        const wrapper = mount(TokensView, { props: { payload: empty } });

        expect(wrapper.text()).toContain('No tokens yet.');
    });

    it('CAP-021 creates a token with a name, an expiry and a scope, and shows it once', async () => {
        writeMock.mockResolvedValueOnce(created);
        readMock.mockResolvedValueOnce(content);
        const wrapper = mount(TokensView, { props: { payload: empty } });

        await wrapper.get('[data-test="new"]').trigger('click');
        await wrapper.get('input[name="token-name"]').setValue('Claude Desktop');
        await wrapper.get('[role="radiogroup"][aria-label="Expires after"] [role="radio"]:nth-child(2)').trigger('click');
        await wrapper.get('[role="radiogroup"][aria-label="Scope"] [role="radio"]:nth-child(2)').trigger('click');
        await wrapper.get('[data-test="create"]').trigger('click');
        await flushPromises();

        expect(sent('token_create')).toEqual({ id: expect.any(String), name: 'Claude Desktop', expires_in_days: 90, scope: 'read_only', capabilities: [] });
        expect(wrapper.get('[data-test="plain-text"]').text()).toBe('7|s3cr3t-token');
        expect(wrapper.text()).toContain('You will not see it again');

        await wrapper.get('[data-test="done"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-test="plain-text"]').exists()).toBe(false);
        expect(readMock).toHaveBeenCalledWith('token_list', {}, expect.any(Function), expect.any(Function));
    });

    it('CAP-021 sends the capabilities picked for a custom scope', async () => {
        writeMock.mockResolvedValueOnce(created);
        const wrapper = mount(TokensView, { props: { payload: content } });

        await wrapper.get('[data-test="new"]').trigger('click');
        await wrapper.get('input[name="token-name"]').setValue('Scale');
        await wrapper.get('[role="radiogroup"][aria-label="Scope"] [role="radio"]:nth-child(4)').trigger('click');
        await wrapper.get('input[value="weigh_in_log"]').setValue(true);
        await wrapper.get('input[value="steps_log"]').setValue(true);
        await wrapper.get('[data-test="create"]').trigger('click');
        await flushPromises();

        expect(sent('token_create')).toMatchObject({ scope: 'custom', expires_in_days: 30, capabilities: [{ name: 'weigh_in_log' }, { name: 'steps_log' }] });
    });

    it('CAP-019 makes a new id for each token', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('token_create', 500, null)).mockResolvedValueOnce(created);
        const wrapper = mount(TokensView, { props: { payload: content } });
        await wrapper.get('[data-test="new"]').trigger('click');
        await wrapper.get('input[name="token-name"]').setValue('A');

        await wrapper.get('[data-test="create"]').trigger('click');
        await flushPromises();
        await wrapper.get('[data-test="create"]').trigger('click');
        await flushPromises();

        const [first, second] = writeMock.mock.calls.map(([, input]) => (input as { id: string }).id);
        expect(first).toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/);
        // A failed create left no row, but a retry that did reach the server must not collide.
        expect(second).not.toBe(first);
    });

    it('CAP-012 shows a refused create in the server\'s words', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('token_create', 422, { ok: false, error: { message: 'Pick at least one action.' } }));
        const wrapper = mount(TokensView, { props: { payload: content } });

        await wrapper.get('[data-test="new"]').trigger('click');
        await wrapper.get('input[name="token-name"]').setValue('Empty');
        await wrapper.get('[data-test="create"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('Pick at least one action.');
    });

    it('CAP-021 revokes a token after the user confirms', async () => {
        writeMock.mockResolvedValueOnce({ invalidates: ['token_list'] });
        readMock.mockResolvedValueOnce({ ...content, tokens: content.tokens.slice(1) });
        const wrapper = mount(TokensView, { props: { payload: content } });
        const first = content.tokens[0]!;

        await wrapper.get(`[data-test="revoke-${first.id}"]`).trigger('click');
        expect(writeMock).not.toHaveBeenCalled();
        await wrapper.get(`[data-test="confirm-revoke-${first.id}"]`).trigger('click');
        await flushPromises();

        expect(sent('token_revoke')).toEqual({ id: first.id });
        expect(wrapper.text()).not.toContain(first.last_used_text);
    });

    it('PWA-024 shows a skeleton while the list loads', () => {
        readMock.mockReturnValueOnce(new Promise(() => {}));
        const wrapper = mount(TokensView);

        expect(wrapper.find('[data-test="skeleton"]').exists()).toBe(true);
    });

    it('CAP-004 says the tokens did not load', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('token_list', 500, null));
        const wrapper = mount(TokensView);
        await flushPromises();

        expect(wrapper.text()).toContain('Your tokens could not be loaded.');
    });
});
