import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, writeNow } from '@/app/call';
import type { TokenCreateOutput } from '@/contracts/token_create';
import type { TokenListOutput } from '@/contracts/token_list';
import { inputViolations, screenFixture } from '@/testing/schemas';
import TokensView from './TokensView.vue';

vi.mock('@/app/call', async (original) => ({ ...(await original<typeof import('@/app/call')>()), read: vi.fn(), writeNow: vi.fn() }));

const readMock = vi.mocked(read);
const writeMock = vi.mocked(writeNow);
const content = screenFixture<TokenListOutput>('token_list');
const empty = screenFixture<TokenListOutput>('token_list.empty');
const expiring = screenFixture<TokenListOutput>('token_list.warning');

const created: TokenCreateOutput = {
    invalidates: ['token_list'],
    token: { id: '01JC0000000000000000000000', name: 'Claude Desktop', plain_text: '7|s3cr3t-token', scope_text: 'Read only', expires_text: 'Expires 31 January 2027' },
};

const EXPIRY = '[role="radiogroup"][aria-label="Expires after"] [role="radio"]';
const SCOPE = '[role="radiogroup"][aria-label="Scope"] [role="radio"]';

async function fill(wrapper: ReturnType<typeof mount>, name: string, expiry = 1, scope = 2): Promise<void> {
    await wrapper.get('input[name="token-name"]').setValue(name);
    await wrapper.get(`${EXPIRY}:nth-child(${expiry})`).trigger('click');
    await wrapper.get(`${SCOPE}:nth-child(${scope})`).trigger('click');
}

function sent(capability: string): Record<string, unknown> {
    const call = writeMock.mock.calls.find(([name]) => name === capability)!;
    expect(inputViolations(capability, call[1])).toEqual([]);
    return call[1] as Record<string, unknown>;
}

// The confirmations are BottomSheets, which teleport to <body>; render them in place.
const global = { stubs: { teleport: true } };

describe('TokensView', () => {
    beforeEach(() => {
        readMock.mockReset();
        writeMock.mockReset();
    });

    it('CAP-021 lists each token with its scope, expiry and when it was last used', () => {
        const wrapper = mount(TokensView, { global, props: { payload: content } });

        for (const token of content.tokens) {
            for (const text of [token.name, token.scope_text, token.expires_text, token.last_used_text, token.status.text]) {
                expect(wrapper.text()).toContain(text);
            }
        }
        expect(readMock).not.toHaveBeenCalled();
    });

    it('CAP-021 shows the server\'s warning for a token about to expire', () => {
        const wrapper = mount(TokensView, { global, props: { payload: expiring } });

        expect(wrapper.text()).toContain('Expires in 3 days');
    });

    it('CAP-021 says there are no tokens yet', () => {
        const wrapper = mount(TokensView, { global, props: { payload: empty } });

        expect(wrapper.text()).toContain('No tokens yet.');
    });

    it('CAP-021 creates a token with a name, an expiry and a scope, and shows it once', async () => {
        writeMock.mockResolvedValueOnce(created);
        readMock.mockResolvedValueOnce(content);
        const wrapper = mount(TokensView, { global, props: { payload: empty } });

        await wrapper.get('[data-test="new"]').trigger('click');
        await fill(wrapper, 'Claude Desktop', 2, 2);
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
        const wrapper = mount(TokensView, { global, props: { payload: content } });

        await wrapper.get('[data-test="new"]').trigger('click');
        await fill(wrapper, 'Scale', 1, 4);
        await wrapper.get('input[value="weigh_in_log"]').setValue(true);
        await wrapper.get('input[value="steps_log"]').setValue(true);
        await wrapper.get('[data-test="create"]').trigger('click');
        await flushPromises();

        expect(sent('token_create')).toMatchObject({ scope: 'custom', expires_in_days: 30, capabilities: [{ name: 'weigh_in_log' }, { name: 'steps_log' }] });
    });

    it('CAP-021 heads the expiry and scope choices on screen, as it does the name', async () => {
        const wrapper = mount(TokensView, { global, props: { payload: content } });

        await wrapper.get('[data-test="new"]').trigger('click');

        const form = wrapper.get('form').text();
        expect(form).toContain('Expires after');
        expect(form).toContain('Scope');
    });

    it('CAP-021 picks no expiry or scope for the user, so Create waits for both', async () => {
        const wrapper = mount(TokensView, { global, props: { payload: content } });
        const create = () => wrapper.get<HTMLButtonElement>('[data-test="create"]').element;

        await wrapper.get('[data-test="new"]').trigger('click');
        expect(wrapper.findAll(`${SCOPE}[aria-checked="true"]`)).toHaveLength(0);
        expect(wrapper.findAll(`${EXPIRY}[aria-checked="true"]`)).toHaveLength(0);

        await wrapper.get('input[name="token-name"]').setValue('Laptop');
        expect(create().disabled).toBe(true);
        await wrapper.get(`${EXPIRY}:nth-child(1)`).trigger('click');
        expect(create().disabled).toBe(true);
        await wrapper.get(`${SCOPE}:nth-child(2)`).trigger('click');
        expect(create().disabled).toBe(false);
    });

    it('CAP-019 sends the same id when a create is retried, so a lost answer never makes a second token', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('token_create', 0, { reason: 'timeout' })).mockResolvedValueOnce(created);
        const wrapper = mount(TokensView, { global, props: { payload: content } });
        await wrapper.get('[data-test="new"]').trigger('click');
        await fill(wrapper, 'A');

        await wrapper.get('[data-test="create"]').trigger('click');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('No connection');
        await wrapper.get('[data-test="create"]').trigger('click');
        await flushPromises();

        const [first, second] = writeMock.mock.calls.map(([, input]) => (input as { id: string }).id);
        expect(first).toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/);
        expect(second).toBe(first);
        // CAP-014: the same key too, so the server replays the first create.
        const keys = writeMock.mock.calls.map(([, , options]) => options?.idempotencyKey);
        expect(keys[0]).toMatch(/^token_create:/);
        expect(keys[1]).toBe(keys[0]);
    });

    it('CAP-014 locks the form after a create with no answer, so the retry sends the same details', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('token_create', 0, { reason: 'timeout' }));
        const wrapper = mount(TokensView, { global, props: { payload: content } });
        await wrapper.get('[data-test="new"]').trigger('click');
        await fill(wrapper, 'A');

        await wrapper.get('[data-test="create"]').trigger('click');
        await flushPromises();

        expect(wrapper.get<HTMLInputElement>('input[name="token-name"]').element.disabled).toBe(true);
        expect(wrapper.findAll<HTMLButtonElement>(SCOPE).every((radio) => radio.element.disabled)).toBe(true);
        expect(wrapper.find('[data-test="locked"]').exists()).toBe(true);
        expect(wrapper.get<HTMLButtonElement>('[data-test="create"]').element.disabled).toBe(false);
    });

    it('CAP-014 takes a new key after a refused create, since the server keeps the refusal under the old one', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('token_create', 422, { ok: false, error: { message: 'Pick at least one action.' } })).mockResolvedValueOnce(created);
        const wrapper = mount(TokensView, { global, props: { payload: content } });
        await wrapper.get('[data-test="new"]').trigger('click');
        await fill(wrapper, 'A', 1, 4);
        await wrapper.get('[data-test="create"]').trigger('click');
        await flushPromises();

        // Answered, so the form stays editable and the next try is a new request.
        await wrapper.get('input[value="steps_log"]').setValue(true);
        await wrapper.get('[data-test="create"]').trigger('click');
        await flushPromises();

        const keys = writeMock.mock.calls.map(([, , options]) => options?.idempotencyKey);
        expect(keys[1]).not.toBe(keys[0]);
    });

    it('CAP-019 lists the token and says its secret is lost when the id already exists', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('token_create', 409, { ok: false, error: { message: 'This token already exists.' } }));
        readMock.mockResolvedValueOnce(content);
        const wrapper = mount(TokensView, { global, props: { payload: empty } });
        await wrapper.get('[data-test="new"]').trigger('click');
        await fill(wrapper, 'A');

        await wrapper.get('[data-test="create"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-test="notice"]').text()).toContain("secret can't be shown again");
        expect(wrapper.find('[data-test="create"]').exists()).toBe(false);
        expect(readMock).toHaveBeenCalledWith('token_list', {}, expect.any(Function), expect.any(Function));
        expect(wrapper.text()).toContain(content.tokens[0]!.name);
    });

    it('CAP-014 a create the server is still running is not a lost secret: the form stays locked for the same retry', async () => {
        writeMock
            .mockRejectedValueOnce(
                new CapabilityError('token_create', 409, { ok: false, error: { code: 'conflict', retryable: true, message: 'This change is still being saved. Try again in a moment.' } }),
            )
            .mockResolvedValueOnce(created);
        const wrapper = mount(TokensView, { global, props: { payload: content } });
        await wrapper.get('[data-test="new"]').trigger('click');
        await fill(wrapper, 'A');

        await wrapper.get('[data-test="create"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-test="notice"]').exists()).toBe(false);
        expect(wrapper.get('[role="alert"]').text()).toContain('This change is still being saved.');
        expect(wrapper.get<HTMLInputElement>('input[name="token-name"]').element.disabled).toBe(true);

        await wrapper.get('[data-test="create"]').trigger('click');
        await flushPromises();

        const keys = writeMock.mock.calls.map(([, , options]) => options?.idempotencyKey);
        expect(keys[1]).toBe(keys[0]);
        expect(wrapper.text()).toContain(created.token.plain_text);
    });

    it('CAP-019 reads the list again when the form closes after a create with no answer', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('token_create', 0, { reason: 'timeout' }));
        readMock.mockResolvedValueOnce(content);
        const wrapper = mount(TokensView, { global, props: { payload: empty } });
        await wrapper.get('[data-test="new"]').trigger('click');
        await fill(wrapper, 'A');
        await wrapper.get('[data-test="create"]').trigger('click');
        await flushPromises();

        await wrapper.findAll('button').find((b) => b.text() === 'Cancel')!.trigger('click');
        await flushPromises();

        expect(readMock).toHaveBeenCalledTimes(1);
        expect(wrapper.text()).toContain(content.tokens[0]!.name);
    });

    it('CAP-021 sends no capabilities once the scope moves off Custom', async () => {
        writeMock.mockResolvedValueOnce(created);
        const wrapper = mount(TokensView, { global, props: { payload: content } });

        await wrapper.get('[data-test="new"]').trigger('click');
        await fill(wrapper, 'Scale', 1, 4);
        await wrapper.get('input[value="weigh_in_log"]').setValue(true);
        await wrapper.get('input[value="steps_log"]').setValue(true);
        await wrapper.get(`${SCOPE}:nth-child(2)`).trigger('click');
        await wrapper.get('[data-test="create"]').trigger('click');
        await flushPromises();

        expect(sent('token_create')).toMatchObject({ scope: 'read_only', capabilities: [] });
    });

    it('CAP-021 says a replayed create cannot show the secret again', async () => {
        writeMock.mockResolvedValueOnce({ ...created, token: { ...created.token, plain_text: null } });
        const wrapper = mount(TokensView, { global, props: { payload: content } });
        await wrapper.get('[data-test="new"]').trigger('click');
        await fill(wrapper, 'A');

        await wrapper.get('[data-test="create"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-test="plain-text"]').exists()).toBe(false);
        expect(wrapper.get('[data-test="secret-lost"]').text()).toContain('Revoke it from the list and make a new one.');
    });

    it('CAP-019 makes a new id for the next token', async () => {
        writeMock.mockResolvedValue(created);
        readMock.mockResolvedValue(content);
        const wrapper = mount(TokensView, { global, props: { payload: content } });

        for (const name of ['A', 'B']) {
            await wrapper.get('[data-test="new"]').trigger('click');
            await fill(wrapper, name);
            await wrapper.get('[data-test="create"]').trigger('click');
            await flushPromises();
            await wrapper.get('[data-test="done"]').trigger('click');
            await flushPromises();
        }

        const [first, second] = writeMock.mock.calls.map(([, input]) => (input as { id: string }).id);
        expect(second).not.toBe(first);
    });

    it('CAP-012 shows a refused create in the server\'s words', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('token_create', 422, { ok: false, error: { message: 'Pick at least one action.' } }));
        const wrapper = mount(TokensView, { global, props: { payload: content } });

        await wrapper.get('[data-test="new"]').trigger('click');
        await fill(wrapper, 'Empty', 1, 4);
        await wrapper.get('[data-test="create"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('Pick at least one action.');
    });

    it('CAP-021 revokes a token after the user confirms', async () => {
        writeMock.mockResolvedValueOnce({ invalidates: ['token_list'] });
        readMock.mockResolvedValueOnce({ ...content, tokens: content.tokens.slice(1) });
        const wrapper = mount(TokensView, { global, props: { payload: content } });
        const first = content.tokens[0]!;

        await wrapper.get(`[data-test="revoke-${first.id}"]`).trigger('click');
        expect(writeMock).not.toHaveBeenCalled();
        await wrapper.get('[role="dialog"] [data-save]').trigger('click');
        await flushPromises();

        expect(sent('token_revoke')).toEqual({ id: first.id });
        expect(writeMock.mock.calls[0]![2]?.idempotencyKey).toMatch(/^token_revoke:/);
        expect(wrapper.text()).not.toContain(first.last_used_text);
    });

    it('CAP-012 keeps a token listed and the confirmation open when the revoke fails', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('token_revoke', 0, { reason: 'network' }));
        const wrapper = mount(TokensView, { global, props: { payload: content } });
        const first = content.tokens[0]!;

        await wrapper.get(`[data-test="revoke-${first.id}"]`).trigger('click');
        await wrapper.get('[role="dialog"] [data-save]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('No connection');
        expect(wrapper.find('[role="dialog"] [data-save]').exists()).toBe(true);
        expect(wrapper.text()).toContain(first.last_used_text);
        expect(readMock).not.toHaveBeenCalled();
    });

    it('CAP-014 retries a revoke that got no answer with the same key', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('token_revoke', 0, { reason: 'timeout' })).mockResolvedValueOnce({ invalidates: ['token_list'] });
        readMock.mockResolvedValueOnce({ ...content, tokens: content.tokens.slice(1) });
        const wrapper = mount(TokensView, { global, props: { payload: content } });
        const first = content.tokens[0]!;

        await wrapper.get(`[data-test="revoke-${first.id}"]`).trigger('click');
        await wrapper.get('[role="dialog"] [data-save]').trigger('click');
        await flushPromises();
        await wrapper.get('[role="dialog"] [data-save]').trigger('click');
        await flushPromises();

        const keys = writeMock.mock.calls.map(([, , options]) => options?.idempotencyKey);
        expect(keys[1]).toBe(keys[0]);
        expect(wrapper.text()).not.toContain(first.last_used_text);
    });

    it('CAP-014 a revoke of another token after an unanswered one takes a new key', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('token_revoke', 0, { reason: 'timeout' })).mockResolvedValueOnce({ invalidates: ['token_list'] });
        readMock.mockResolvedValueOnce(content);
        const wrapper = mount(TokensView, { global, props: { payload: content } });
        const [first, second] = content.tokens;

        await wrapper.get(`[data-test="revoke-${first!.id}"]`).trigger('click');
        await wrapper.get('[role="dialog"] [data-save]').trigger('click');
        await flushPromises();
        await wrapper.findAll('[role="dialog"] button').find((b) => b.text() === 'Cancel')!.trigger('click');
        await flushPromises();
        await wrapper.get(`[data-test="revoke-${second!.id}"]`).trigger('click');
        await wrapper.get('[role="dialog"] [data-save]').trigger('click');
        await flushPromises();

        expect(writeMock.mock.calls[1]![1]).toEqual({ id: second!.id });
        const keys = writeMock.mock.calls.map(([, , options]) => options?.idempotencyKey);
        expect(keys[1]).not.toBe(keys[0]);
    });

    it('CAP-021 treats a revoke of a token that is already gone as done', async () => {
        writeMock.mockRejectedValueOnce(new CapabilityError('token_revoke', 404, { ok: false, error: { message: 'Not found.' } }));
        readMock.mockResolvedValueOnce({ ...content, tokens: content.tokens.slice(1) });
        const wrapper = mount(TokensView, { global, props: { payload: content } });
        const first = content.tokens[0]!;

        await wrapper.get(`[data-test="revoke-${first.id}"]`).trigger('click');
        await wrapper.get('[role="dialog"] [data-save]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain(first.last_used_text);
    });

    it('UI-030 shows each token status as a pill with its tone icon', () => {
        const wrapper = mount(TokensView, { global, props: { payload: expiring } });

        expect(wrapper.findAllComponents({ name: 'StatusPill' }).map((pill) => pill.props('tone'))).toEqual(['good', 'warning']);
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

    it('OFF-003 says the tokens need a connection when offline with nothing cached, with no retry that cannot work', async () => {
        readMock.mockRejectedValueOnce(new CapabilityError('token_list', 0, { reason: 'network' }));
        const wrapper = mount(TokensView);
        await flushPromises();

        expect(wrapper.text()).toContain('Not available offline');
        expect(wrapper.text()).toContain("Your tokens will load when you're back online.");
        expect(wrapper.find('[data-test="retry"]').exists()).toBe(false);
    });
});
