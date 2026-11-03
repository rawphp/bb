import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError } from './call';
import { cancel, events, history, send } from './chatTransport';

function json(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function clearCookies(): void {
    for (const cookie of document.cookie.split(';')) {
        const name = cookie.split('=')[0]?.trim();
        if (name) document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
    }
}

const CONVERSATION = '01JC0ACH00000000000000C0NV';
const TURN = '01JC0ACH00000000000000TRN1';

describe('chatTransport', () => {
    const fetchMock = vi.fn<typeof fetch>();

    beforeEach(() => {
        clearCookies();
        document.cookie = 'XSRF-TOKEN=abc%3D%3D; path=/';
        fetchMock.mockReset();
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => vi.unstubAllGlobals());

    it('AGT-021 reads a conversation\'s history with the session cookie', async () => {
        const body = { conversation_ulid: CONVERSATION, messages: [], proposals: [] };
        fetchMock.mockResolvedValueOnce(json(body));

        expect(await history(CONVERSATION)).toEqual(body);
        const [url, init] = fetchMock.mock.calls[0]!;
        expect(url).toBe(`/capabilities-ai/chat/conversations/${CONVERSATION}`);
        expect(init?.method).toBe('GET');
        expect(init?.credentials).toBe('same-origin');
        expect(init?.headers).toMatchObject({ Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' });
    });

    it.each([
        [CONVERSATION, { content: 'Swap my legs day', conversation_ulid: CONVERSATION }],
        [null, { content: 'Swap my legs day' }],
    ])('AGT-021 posts a message with the CSRF header, naming the conversation only when there is one', async (conversation, sent) => {
        const ids = { conversation_ulid: CONVERSATION, message_ulid: '01JC0ACH0000000000000MSG01', turn_ulid: TURN };
        fetchMock.mockResolvedValueOnce(json(ids, 201));

        expect(await send('Swap my legs day', conversation)).toEqual(ids);
        const [url, init] = fetchMock.mock.calls[0]!;
        expect(url).toBe('/capabilities-ai/chat/messages');
        expect(init?.method).toBe('POST');
        expect(init?.credentials).toBe('same-origin');
        expect(JSON.parse(init?.body as string)).toEqual(sent);
        expect(init?.headers).toMatchObject({
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': 'abc==',
        });
    });

    it('AGT-021 fetches the CSRF cookie first when it is missing', async () => {
        clearCookies();
        fetchMock.mockImplementationOnce(async () => {
            document.cookie = 'XSRF-TOKEN=fresh; path=/';
            return new Response(null, { status: 204 });
        });
        fetchMock.mockResolvedValueOnce(json({ turn_ulid: TURN, status: 'cancelled' }));

        await cancel(TURN);

        expect(fetchMock.mock.calls[0]![0]).toBe('/sanctum/csrf-cookie');
        expect(fetchMock.mock.calls[1]![1]?.headers).toMatchObject({ 'X-XSRF-TOKEN': 'fresh' });
    });

    it('AGT-021 cancels a turn', async () => {
        fetchMock.mockResolvedValueOnce(json({ turn_ulid: TURN, status: 'cancelled' }));

        expect(await cancel(TURN)).toEqual({ turn_ulid: TURN, status: 'cancelled' });
        const [url, init] = fetchMock.mock.calls[0]!;
        expect(url).toBe(`/capabilities-ai/chat/turns/${TURN}/cancel`);
        expect(init?.method).toBe('POST');
    });

    it('AGT-021 reads a turn\'s events from a cursor', async () => {
        const body = { turn_ulid: TURN, events: [{ kind: 'terminal', data: { status: 'completed' }, index: 3 }] };
        fetchMock.mockResolvedValueOnce(json(body));

        expect(await events(TURN, 3)).toEqual(body);
        const [url, init] = fetchMock.mock.calls[0]!;
        expect(url).toBe(`/capabilities-ai/chat/turns/${TURN}/events?cursor=3`);
        expect(init?.method).toBe('GET');
    });

    it('CAP-012 turns the package\'s error envelope into a CapabilityError', async () => {
        const envelope = { ok: false, error: { code: 'conflict', message: 'Turn cannot be cancelled' } };
        fetchMock.mockResolvedValueOnce(json(envelope, 409));

        const error = await cancel(TURN).catch((e: unknown) => e);

        expect(error).toBeInstanceOf(CapabilityError);
        expect(error).toMatchObject({ status: 409, body: envelope });
    });

    it('OFF-031 is a CapabilityError with status 0 when no answer comes', async () => {
        fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'));

        const error = await history(CONVERSATION).catch((e: unknown) => e);

        expect(error).toBeInstanceOf(CapabilityError);
        expect(error).toMatchObject({ status: 0, body: { reason: 'network' } });
    });
});
