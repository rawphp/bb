import 'fake-indexeddb/auto';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cached, clearReads, MAX_AGE_MS, store } from './cache';
import { CapabilityError, keepWarm, read, TIMEOUT_MS, warm, write } from './call';

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

    beforeEach(async () => {
        await clearReads();
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

    describe('stale-while-revalidate reads', () => {
        // Answers each capability from `data`; a capability missing from it fails like the network.
        function serve(data: Record<string, unknown>): void {
            document.cookie = 'XSRF-TOKEN=t; path=/';
            fetchMock.mockImplementation(async (url) => {
                const name = String(url).replace('/capabilities/', '');
                if (!(name in data)) throw new TypeError('Failed to fetch');
                return json({ ok: true, data: data[name] });
            });
        }

        it('OFF-003 shows the cached copy at once and hands the refreshed one to onUpdate', async () => {
            await store('today_show{"date":"2026-11-01"}', { title: 'cached' });
            serve({ today_show: { title: 'fresh' } });
            const onUpdate = vi.fn();

            const data = await read('today_show', { date: '2026-11-01' }, onUpdate);

            expect(data).toEqual({ title: 'cached' });
            await vi.waitFor(() => expect(onUpdate).toHaveBeenCalledWith({ title: 'fresh' }));
            expect((await cached('today_show{"date":"2026-11-01"}'))?.data).toEqual({ title: 'fresh' });
        });

        it('OFF-003 waits for the server when nothing is cached, and caches the answer under its read key', async () => {
            serve({ meal_show: { meal: 2 } });

            expect(await read('meal_show', { meal: 2, date: '2026-11-01' })).toEqual({ meal: 2 });
            expect(await cached('meal_show{"date":"2026-11-01","meal":2}')).toMatchObject({ data: { meal: 2 } });
        });

        it('OFF-003 keeps the cached copy when the refresh fails offline', async () => {
            await store('today_show{}', { title: 'cached' }, 5);
            serve({});
            const onUpdate = vi.fn();

            expect(await read('today_show', {}, onUpdate)).toEqual({ title: 'cached' });
            await vi.waitFor(() => expect(fetchMock).toHaveBeenCalled());
            await new Promise((resolve) => setTimeout(resolve, 0));

            expect(onUpdate).not.toHaveBeenCalled();
            expect(await cached('today_show{}')).toEqual({ key: 'today_show{}', data: { title: 'cached' }, fetchedAt: 5 });
        });

        it('OFF-003 fails with status 0 when nothing is cached and there is no network', async () => {
            serve({});

            await expect(read('today_show')).rejects.toMatchObject({ status: 0, body: { reason: 'network' } });
        });

        it('OFF-004 fetches every read key sync_manifest lists into the cache', async () => {
            serve({
                sync_manifest: { keys: ['today_show{"date":"2026-11-01"}', 'workout_show{"id":"01J"}'] },
                today_show: { title: 'Today' },
                workout_show: { id: '01J' },
            });
            vi.stubGlobal('navigator', { storage: { persist: vi.fn(async () => true), persisted: async () => false } });

            await warm();

            expect(fetchMock.mock.calls.find(([url]) => url === '/capabilities/workout_show')?.[1]?.body).toBe('{"id":"01J"}');
            expect((await cached('today_show{"date":"2026-11-01"}'))?.data).toEqual({ title: 'Today' });
            expect((await cached('workout_show{"id":"01J"}'))?.data).toEqual({ id: '01J' });
        });

        it('OFF-005 warming drops old reads the manifest no longer lists, and asks for persistent storage', async () => {
            const old = Date.now() - MAX_AGE_MS - 1;
            await store('today_show{"date":"2026-09-01"}', {}, old);
            await store('mesocycle_show{}', {}, old);
            serve({ sync_manifest: { keys: ['mesocycle_show{}'] } });
            const persist = vi.fn(async () => true);
            vi.stubGlobal('navigator', { storage: { persist, persisted: async () => false } });

            await warm();

            expect(await cached('today_show{"date":"2026-09-01"}')).toBeUndefined();
            expect(await cached('mesocycle_show{}')).toBeDefined();
            expect(persist).toHaveBeenCalledTimes(1);
        });

        it('OFF-005 does not ask for persistent storage before onboarding, while the manifest is empty', async () => {
            serve({ sync_manifest: { keys: [] } });
            const persist = vi.fn(async () => true);
            vi.stubGlobal('navigator', { storage: { persist, persisted: async () => false } });

            await warm();

            expect(persist).not.toHaveBeenCalled();
        });

        it('OFF-004 warms the cache on open and again on reconnect', async () => {
            serve({ sync_manifest: { keys: [] } });
            const target = new EventTarget() as Window;
            const manifestCalls = () => fetchMock.mock.calls.filter(([url]) => url === '/capabilities/sync_manifest').length;

            keepWarm(target);
            await vi.waitFor(() => expect(manifestCalls()).toBe(1));

            target.dispatchEvent(new Event('online'));
            await vi.waitFor(() => expect(manifestCalls()).toBe(2));
        });
    });
});
