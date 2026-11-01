import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, TIMEOUT_MS, write } from './call';
import { clearWalk, walkDays } from './clock';

function json(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function clearCookies(): void {
    for (const cookie of document.cookie.split(';')) {
        const name = cookie.split('=')[0]?.trim();
        if (name) document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
    }
}

describe('call', () => {
    const fetchMock = vi.fn<typeof fetch>();

    beforeEach(() => {
        clearCookies();
        fetchMock.mockReset();
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => vi.unstubAllGlobals());

    it.each([
        ['read', read],
        ['write', write],
    ] as const)('CAP-004 %s posts to /capabilities/{name} with the CSRF cookie and returns the data', async (_, call) => {
        document.cookie = 'XSRF-TOKEN=abc%3D%3D; path=/';
        fetchMock.mockResolvedValueOnce(json({ ok: true, data: { name: 'Sam' } }));

        const data = await call('account_show', { verbose: true });

        expect(data).toEqual({ name: 'Sam' });
        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, init] = fetchMock.mock.calls[0]!;
        expect(url).toBe('/capabilities/account_show');
        expect(init?.method).toBe('POST');
        expect(init?.credentials).toBe('same-origin');
        expect(init?.body).toBe(JSON.stringify({ verbose: true }));
        expect(init?.headers).toMatchObject({
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': 'abc==',
        });
    });

    it('SET-011 sends X-BB-Now only while the clock is walked', async () => {
        document.cookie = 'XSRF-TOKEN=t; path=/';
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-11-02T09:30:00.000Z'));
        fetchMock.mockImplementation(async () => json({ ok: true, data: {} }));

        try {
            await read('account_show');
            walkDays(7);
            await write('account_show');
            clearWalk();
            await read('account_show');
        } finally {
            clearWalk();
            vi.useRealTimers();
        }

        const headers = fetchMock.mock.calls.map(([, init]) => init?.headers as Record<string, string>);
        expect(headers[0]).not.toHaveProperty('X-BB-Now');
        expect(headers[1]).toMatchObject({ 'X-BB-Now': '2026-11-09T09:30:00.000Z' });
        expect(headers[2]).not.toHaveProperty('X-BB-Now');
    });

    it('CAP-004 fetches the CSRF cookie first when it is missing', async () => {
        fetchMock.mockImplementationOnce(async () => {
            document.cookie = 'XSRF-TOKEN=fresh; path=/';
            return new Response(null, { status: 204 });
        });
        fetchMock.mockResolvedValueOnce(json({ ok: true, data: {} }));

        await write('account_show', {});

        expect(fetchMock.mock.calls[0]![0]).toBe('/sanctum/csrf-cookie');
        expect(fetchMock.mock.calls[1]![1]?.headers).toMatchObject({ 'X-XSRF-TOKEN': 'fresh' });
    });

    it('CAP-004 sends an empty object when there is no input', async () => {
        document.cookie = 'XSRF-TOKEN=t; path=/';
        fetchMock.mockResolvedValueOnce(json({ ok: true, data: {} }));

        await read('account_show');

        expect(fetchMock.mock.calls[0]![1]?.body).toBe('{}');
    });

    it('CAP-004 throws a CapabilityError with the status and body when the call fails', async () => {
        document.cookie = 'XSRF-TOKEN=t; path=/';
        fetchMock.mockResolvedValueOnce(json({ message: 'Unauthenticated.' }, 401));

        const error = await read('account_show').catch((e: unknown) => e);

        expect(error).toBeInstanceOf(CapabilityError);
        expect(error).toMatchObject({ capability: 'account_show', status: 401, body: { message: 'Unauthenticated.' } });
    });

    it('CAP-004 throws when the envelope says the call did not succeed', async () => {
        document.cookie = 'XSRF-TOKEN=t; path=/';
        fetchMock.mockResolvedValueOnce(json({ ok: false, error: { code: 'nope' } }));

        await expect(write('account_show', {})).rejects.toMatchObject({ status: 200, body: { ok: false } });
    });

    it.each([
        ['the capability call', true],
        ['the CSRF cookie fetch', false],
    ])('CAP-004 gives up on a stalled server when %s never answers', async (_, hasCookie) => {
        vi.useFakeTimers();
        if (hasCookie) document.cookie = 'XSRF-TOKEN=t; path=/';
        fetchMock.mockImplementation(
            (_url, init) =>
                new Promise<Response>((_resolve, reject) => {
                    init?.signal?.addEventListener('abort', () => reject(init.signal!.reason));
                }),
        );

        let settled = false;
        const error = read('account_show').catch((e: unknown) => e).finally(() => (settled = true));
        await vi.advanceTimersByTimeAsync(TIMEOUT_MS - 1);
        expect(settled).toBe(false);
        await vi.advanceTimersByTimeAsync(1);

        expect(await error).toBeInstanceOf(CapabilityError);
        expect(await error).toMatchObject({ capability: 'account_show', status: 0, body: { reason: 'timeout' } });
        expect(fetchMock.mock.calls[0]![1]?.signal).toBeInstanceOf(AbortSignal);
        vi.useRealTimers();
    });

    it('CAP-004 throws a CapabilityError with status 0 when the network fails', async () => {
        document.cookie = 'XSRF-TOKEN=t; path=/';
        fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'));

        await expect(write('account_show', {})).rejects.toMatchObject({ status: 0, body: { reason: 'network' } });
    });
});
