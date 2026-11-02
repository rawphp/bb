import 'fake-indexeddb/auto';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cached, clearReads, MAX_AGE_MS, store } from './cache';
import { CapabilityError, keepWarm, postHost, read, TIMEOUT_MS, WARM_CONCURRENCY, WARM_FRESH_MS, warm, write } from './call';
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

    beforeEach(async () => {
        clearCookies();
        // Signed in: the server's bb_cache_owner cookie names whose cache to open.
        document.cookie = 'bb_cache_owner=a1; path=/';
        await clearReads();
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

    it('CAP-014 sends the idempotency key a write is given, and none otherwise', async () => {
        document.cookie = 'XSRF-TOKEN=t; path=/';
        fetchMock.mockImplementation(async () => json({ ok: true, data: {} }));

        await write('token_revoke', { id: 'x' }, { idempotencyKey: 'token_revoke:x' });
        await write('token_revoke', { id: 'x' });

        const headers = fetchMock.mock.calls.map(([, init]) => init?.headers as Record<string, string>);
        expect(headers[0]).toMatchObject({ 'Idempotency-Key': 'token_revoke:x' });
        expect(headers[1]).not.toHaveProperty('Idempotency-Key');
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
            expect(await cached('today_show{}')).toMatchObject({ key: 'today_show{}', data: { title: 'cached' }, fetchedAt: 5 });
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

        // Answers each capability with the given status and body.
        function answer(replies: Record<string, [number, unknown]>): void {
            document.cookie = 'XSRF-TOKEN=t; path=/';
            fetchMock.mockImplementation(async (url) => {
                const [status, body] = replies[String(url).replace('/capabilities/', '')] ?? [500, null];
                return json(body, status);
            });
        }

        // Lets every pending promise and IndexedDB request run.
        const settle = () => new Promise((resolve) => setTimeout(resolve, 20));

        it.each([401, 419])('OFF-003 clears every cached read when a refresh answers %s, so no one else sees them', async (status) => {
            await store('account_show{}', { name: 'A' });
            await store('today_show{}', { title: 'A' });
            answer({ account_show: [status, { message: 'Unauthenticated.' }] });

            await read('account_show');
            await settle();

            expect(await cached('account_show{}')).toBeUndefined();
            expect(await cached('today_show{}')).toBeUndefined();
        });

        it.each([403, 404])('OFF-003 drops only the read whose refresh answers %s', async (status) => {
            await store('workout_show{"id":"01J"}', { id: '01J' });
            await store('today_show{}', { title: 'Today' });
            answer({ workout_show: [status, { message: 'Not found.' }] });

            await read('workout_show', { id: '01J' });
            await settle();

            expect(await cached('workout_show{"id":"01J"}')).toBeUndefined();
            expect(await cached('today_show{}')).toBeDefined();
        });

        it.each([401, 403, 404, 419])('OFF-003 tells the screen when a refresh answers %i, so it stops showing the cached copy', async (status) => {
            await store('account_show{}', { name: 'A' });
            answer({ account_show: [status, null] });
            const onUpdate = vi.fn();
            const onError = vi.fn();

            expect(await read('account_show', {}, onUpdate, onError)).toEqual({ name: 'A' });
            await vi.waitFor(() => expect(onError).toHaveBeenCalledWith(expect.objectContaining({ status })));
            expect(onUpdate).not.toHaveBeenCalled();
        });

        it.each([
            ['the network fails', 0],
            ['the server fails', 503],
        ])('OFF-003 does not report a refresh failure when %s', async (_, status) => {
            await store('account_show{}', { name: 'A' });
            if (status === 0) serve({});
            else answer({ account_show: [status, null] });
            const onError = vi.fn();

            await read('account_show', {}, undefined, onError);
            await settle();

            expect(onError).not.toHaveBeenCalled();
        });

        it.each([
            ['a write drops it', () => write('workout_set_log', {})],
            ['the session ends', () => clearReads()],
        ])('OFF-003 a refresh that started before %s asks again instead of showing or storing the old answer', async (_, removeIt) => {
            await store('today_show{}', { sets: 1 });
            let answerFirst: (response: Response) => void = () => {};
            let todayCalls = 0;
            document.cookie = 'XSRF-TOKEN=t; path=/';
            fetchMock.mockImplementation(async (url) => {
                if (url !== '/capabilities/today_show') return json({ ok: true, data: { invalidates: ['today_show{}'] } });
                if (++todayCalls === 1) return new Promise<Response>((resolve) => (answerFirst = resolve));
                return json({ ok: true, data: { sets: 2 } });
            });
            const onUpdate = vi.fn();

            await read('today_show', {}, onUpdate);
            await vi.waitFor(() => expect(todayCalls).toBe(1));
            await removeIt();
            answerFirst(json({ ok: true, data: { sets: 1 } }));
            await vi.waitFor(() => expect(onUpdate).toHaveBeenCalled());

            expect(onUpdate.mock.calls).toEqual([[{ sets: 2 }]]);
            expect((await cached('today_show{}'))?.data).toEqual({ sets: 2 });
        });

        it(`OFF-004 skips a read cached less than ${WARM_FRESH_MS / 1000} s ago and refetches an older one`, async () => {
            await store('today_show{}', { title: 'just now' });
            await store('week_show{}', { title: 'old' }, Date.now() - WARM_FRESH_MS - 1);
            serve({ sync_manifest: { keys: ['today_show{}', 'week_show{}'] }, today_show: { title: 'new' }, week_show: { title: 'new' } });
            vi.stubGlobal('navigator', {});

            await warm();

            expect(fetchMock.mock.calls.map(([url]) => url)).toEqual(['/capabilities/sync_manifest', '/capabilities/week_show']);
            expect((await cached('week_show{}'))?.data).toEqual({ title: 'new' });
        });

        it('OFF-003 keeps the cached copy when the server fails with a 5xx', async () => {
            await store('today_show{}', { title: 'cached' });
            answer({ today_show: [503, null] });

            await read('today_show');
            await settle();

            expect((await cached('today_show{}'))?.data).toEqual({ title: 'cached' });
        });

        it('OFF-004 clears the cache when the warm finds the session gone', async () => {
            await store('today_show{}', { title: 'A' });
            answer({ sync_manifest: [401, { message: 'Unauthenticated.' }] });

            await expect(warm()).rejects.toMatchObject({ status: 401 });

            expect(await cached('today_show{}')).toBeUndefined();
        });

        it('CAP-010 a write drops the cached reads it invalidates, so the next read waits for the server', async () => {
            await store('today_show{}', { sets: 1 });
            await store('week_show{}', { sets: 1 });
            answer({
                workout_set_log: [200, { ok: true, data: { invalidates: ['today_show{}', 7], toast: null, undo: null } }],
                today_show: [200, { ok: true, data: { sets: 2 } }],
            });

            await write('workout_set_log', { reps: 8 });

            expect(await cached('week_show{}')).toBeDefined();
            expect(await read('today_show')).toEqual({ sets: 2 });
        });

        it('OFF-004 still warms the valid keys when the manifest lists a malformed or non-string one', async () => {
            const old = Date.now() - MAX_AGE_MS - 1;
            await store('stale_show{}', {}, old);
            serve({
                sync_manifest: { keys: ['week_show', 'x{bad', 42, 'today_show{}'] },
                today_show: { title: 'Today' },
            });
            vi.stubGlobal('navigator', { storage: { persist: vi.fn(async () => true), persisted: async () => false } });

            await warm();

            expect((await cached('today_show{}'))?.data).toEqual({ title: 'Today' });
            expect(await cached('stale_show{}')).toBeUndefined();
            expect(fetchMock.mock.calls.map(([url]) => url)).toEqual(['/capabilities/sync_manifest', '/capabilities/today_show']);
        });

        it(`OFF-004 sends at most ${WARM_CONCURRENCY} manifest reads at once`, async () => {
            const keys = Array.from({ length: 10 }, (_, i) => `day_show{"day":${i}}`);
            let open = 0;
            let most = 0;
            document.cookie = 'XSRF-TOKEN=t; path=/';
            fetchMock.mockImplementation(async (url) => {
                if (url === '/capabilities/sync_manifest') return json({ ok: true, data: { keys } });
                most = Math.max(most, ++open);
                await new Promise((resolve) => setTimeout(resolve, 5));
                open--;
                return json({ ok: true, data: {} });
            });
            vi.stubGlobal('navigator', {});

            await warm();

            expect(most).toBe(WARM_CONCURRENCY);
            expect(fetchMock).toHaveBeenCalledTimes(11);
            for (const key of keys) expect(await cached(key)).toBeDefined();
        });

        it('OFF-004 a warm asked for while one runs queues one more after it, however often it is asked', async () => {
            serve({ sync_manifest: { keys: [] } });
            const manifestCalls = () => fetchMock.mock.calls.filter(([url]) => url === '/capabilities/sync_manifest').length;

            await Promise.all([warm(), warm(), warm(), warm()]);

            expect(manifestCalls()).toBe(2);
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

describe('postHost', () => {
    const fetchMock = vi.fn<typeof fetch>();

    beforeEach(() => {
        clearCookies();
        fetchMock.mockReset();
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => vi.unstubAllGlobals());

    // A sign-in screen left open outlives its session, so its CSRF cookie may be stale.
    function freshCookie(): void {
        document.cookie = 'XSRF-TOKEN=stale; path=/';
        fetchMock.mockImplementationOnce(async () => {
            document.cookie = 'XSRF-TOKEN=fresh%3D; path=/';
            return new Response(null, { status: 204 });
        });
    }

    it('CAP-004 posts to the host route with a freshly fetched CSRF cookie', async () => {
        freshCookie();
        fetchMock.mockResolvedValueOnce(new Response(null, { status: 204 }));

        await postHost('/sign-in', { email: 'sam@example.com', password: 'secret' });

        expect(fetchMock.mock.calls[0]![0]).toBe('/sanctum/csrf-cookie');
        const [url, init] = fetchMock.mock.calls[1]!;
        expect(url).toBe('/sign-in');
        expect(init?.method).toBe('POST');
        expect(init?.credentials).toBe('same-origin');
        expect(init?.body).toBe(JSON.stringify({ email: 'sam@example.com', password: 'secret' }));
        expect(init?.headers).toMatchObject({
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': 'fresh=',
        });
    });

    it('ACC-001 a refused host call throws a CapabilityError with the status and body', async () => {
        freshCookie();
        const body = { message: 'That code is wrong.', errors: { code: ['That code is wrong.'] } };
        fetchMock.mockResolvedValueOnce(json(body, 422));

        await expect(postHost('/sign-in/code/verify', { email: 'a@b.c', code: '123456' })).rejects.toMatchObject({
            capability: '/sign-in/code/verify',
            status: 422,
            body,
        });
    });

    it('ACC-001 a host call with no answer throws status 0', async () => {
        freshCookie();
        fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'));

        const error = await postHost('/sign-in/code', { email: 'a@b.c' }).catch((e: unknown) => e);

        expect(error).toBeInstanceOf(CapabilityError);
        expect(error).toMatchObject({ status: 0, body: { reason: 'network' } });
    });

    it('CAP-004 a host call gives up on a stalled server', async () => {
        vi.useFakeTimers();
        fetchMock.mockImplementation(
            (_url, init) =>
                new Promise<Response>((_resolve, reject) => {
                    init?.signal?.addEventListener('abort', () => reject(init.signal!.reason));
                }),
        );

        try {
            const error = postHost('/sign-in', {}).catch((e: unknown) => e);
            await vi.advanceTimersByTimeAsync(TIMEOUT_MS);

            expect(await error).toMatchObject({ status: 0, body: { reason: 'timeout' } });
        } finally {
            vi.useRealTimers();
        }
    });
});
