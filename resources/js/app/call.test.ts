import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CapabilityError, read, write } from './call';

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
});
