import { IDBFactory } from 'fake-indexeddb';
import 'fake-indexeddb/auto';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import replayFixture from '../../../tests/fixtures/outbox/replay.json';

// Each test loads call.ts, cache.ts and outbox.ts afresh over an empty IndexedDB, so the
// outbox in memory and on the "device" start empty, and a reload is a second import.
type Modules = { call: typeof import('./call'); outbox: typeof import('./outbox'); cache: typeof import('./cache'); clock: typeof import('./clock') };

async function boot(): Promise<Modules> {
    vi.resetModules();
    const [call, outbox, cache, clock] = await Promise.all([import('./call'), import('./outbox'), import('./cache'), import('./clock')]);

    return { call, outbox, cache, clock };
}

function json(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const ok = (data: unknown = { invalidates: [], toast: null, undo: null }) => json({ ok: true, data });
const refused = (status: number, message: string, field?: string) =>
    json({ ok: false, error: { code: 'validation_failed', message, ...(field ? { violations: [{ field, message }] } : {}) } }, status);

function signIn(owner: string | null): void {
    document.cookie = owner === null ? 'bb_cache_owner=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/' : `bb_cache_owner=${owner}; path=/`;
}

// Lets every pending promise, IndexedDB request and zero-delay timer run.
const settle = () => new Promise((resolve) => setTimeout(resolve, 50));

const label = 'A change';

describe('outbox', () => {
    const fetchMock = vi.fn<typeof fetch>();
    // Writes in the order sent: name and input. The warm after a replay is left out.
    const writes = () => fetchMock.mock.calls.filter(([url]) => String(url).startsWith('/capabilities/') && url !== '/capabilities/sync_manifest');
    const sent = () => writes().map(([url, init]) => [String(url).replace('/capabilities/', ''), JSON.parse(String(init?.body))]);
    const headersOf = (index: number) => writes()[index]![1]!.headers as Record<string, string>;
    let m: Modules;

    beforeEach(async () => {
        globalThis.indexedDB = new IDBFactory();
        localStorage.clear();
        document.cookie = 'XSRF-TOKEN=t; path=/';
        signIn('a1');
        fetchMock.mockReset();
        // By default the device is offline.
        fetchMock.mockRejectedValue(new TypeError('Failed to fetch'));
        vi.stubGlobal('fetch', fetchMock);
        m = await boot();
    });

    afterEach(async () => {
        vi.useRealTimers();
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
        m.clock.clearWalk();
        // A retry timer this boot left must find nothing to send in a later test.
        await m.outbox.removeEntries(m.outbox.entries.value.map((entry) => entry.seq));
        await m.outbox.removeOthers();
    });

    it('OFF-006 stores the entry with everything replay needs before write() returns', async () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-11-02T09:30:00.000Z'));

        const entry = await m.call.write('workout_set_log', { set_id: '01SET', weight: 100, reps: 8 }, { label: 'Logged set 3 of Bench Press, 100 kg × 8', creates: ['01ROW'] });

        expect(entry).toMatchObject({
            user: 'a1',
            name: 'workout_set_log',
            input: { set_id: '01SET', weight: 100, reps: 8 },
            creates: ['01ROW'],
            occurredAt: '2026-11-02T09:30:00.000Z',
            label: 'Logged set 3 of Bench Press, 100 kg × 8',
        });
        expect(entry.idempotencyKey).toMatch(/^workout_set_log:[0-9A-Z]{26}$/);

        // A reload finds it on the device.
        const reloaded = await boot();
        await reloaded.outbox.loadOutbox();
        expect(reloaded.outbox.entries.value).toEqual([entry]);
    });

    it('OFF-006 replays entries one at a time, in the order they were made', async () => {
        const answers: ((response: Response) => void)[] = [];
        fetchMock.mockImplementation((url) => (String(url).startsWith('/capabilities/') ? new Promise<Response>((resolve) => answers.push(resolve)) : Promise.reject(new TypeError('offline'))));

        await m.call.write('workout_set_log', { set: 1 }, { label });
        await m.call.write('workout_set_log', { set: 2 }, { label });
        await m.call.write('workout_finish', { id: 'w' }, { label });
        await settle();

        expect(sent()).toEqual([['workout_set_log', { set: 1 }]]);
        answers.shift()!(ok());
        await settle();
        expect(sent()).toEqual([
            ['workout_set_log', { set: 1 }],
            ['workout_set_log', { set: 2 }],
        ]);
        answers.shift()!(ok());
        await settle();
        answers.shift()!(ok());
        await settle();

        expect(sent().map(([name, input]) => [name, input])).toEqual([
            ['workout_set_log', { set: 1 }],
            ['workout_set_log', { set: 2 }],
            ['workout_finish', { id: 'w' }],
        ]);
        expect(m.outbox.entries.value).toEqual([]);
    });

    it('OFF-006 keeps two writes made in the same millisecond apart and in order', async () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-11-02T09:30:00.000Z'));

        const first = await m.call.write('workout_set_log', { set: 1 }, { label });
        const second = await m.call.write('workout_set_log', { set: 2 }, { label });

        expect(second.seq).toBeGreaterThan(first.seq);
        const reloaded = await boot();
        await reloaded.outbox.loadOutbox();
        expect(reloaded.outbox.entries.value.map((entry) => entry.input)).toEqual([{ set: 1 }, { set: 2 }]);
    });

    it('OFF-006 stores the entry on a fresh connection when the open one was lost', async () => {
        // The first connection the outbox opens has died: every put on it throws.
        let dead: IDBDatabase | undefined;
        const put = IDBObjectStore.prototype.put;
        vi.spyOn(IDBObjectStore.prototype, 'put').mockImplementation(function (this: IDBObjectStore, ...args: Parameters<IDBObjectStore['put']>) {
            dead ??= this.transaction.db;
            if (this.transaction.db === dead) throw new DOMException('Connection to Indexed Database server lost.', 'InvalidStateError');
            return put.apply(this, args);
        });

        await m.call.write('steps_log', { steps: 9000 }, { label });

        vi.restoreAllMocks();
        const reloaded = await boot();
        await reloaded.outbox.loadOutbox();
        expect(reloaded.outbox.entries.value.map((entry) => entry.input)).toEqual([{ steps: 9000 }]);
    });

    it('OFF-006 fails the write, and keeps nothing to send, when the device cannot store it', async () => {
        // Follow the signed-in user first, so entries shows what memory holds for them.
        await m.outbox.loadOutbox();
        vi.spyOn(IDBObjectStore.prototype, 'put').mockImplementation(() => {
            throw new DOMException('The quota has been exceeded.', 'QuotaExceededError');
        });

        await expect(m.call.write('steps_log', { steps: 9000 }, { label })).rejects.toThrow();
        expect(m.outbox.entries.value).toEqual([]);
        await settle();
        expect(sent()).toEqual([]);
    });

    it('OFF-051 sends each entry as tests/fixtures/outbox/replay.json records it', async () => {
        fetchMock.mockImplementation(async () => ok());

        for (const entry of replayFixture.entries) await m.call.write(entry.capability, entry.input, { label });
        await settle();

        const calls = writes();
        expect(calls).toHaveLength(replayFixture.entries.length);
        replayFixture.entries.forEach((entry, index) => {
            const [url, init] = calls[index]!;
            expect(url).toBe(`/capabilities/${entry.capability}`);
            expect(init?.method).toBe('POST');
            expect(JSON.parse(String(init?.body))).toEqual(entry.input);
            for (const header of Object.keys(entry.headers)) expect(init?.headers).toHaveProperty(header);
            expect(headersOf(index)['Idempotency-Key']).toMatch(new RegExp(`^${entry.capability}:[0-9A-Z]{26}$`));
        });
    });

    it('OFF-007 sends the moment the user acted, on the walked clock, however late the replay', async () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-11-02T09:30:00.000Z'));
        m.clock.walkDays(1);

        await m.call.write('meal_check_in', { meal_id: 'm' }, { label });
        await settle();
        vi.setSystemTime(new Date('2026-11-02T18:00:00.000Z'));
        fetchMock.mockImplementation(async () => ok());
        await m.call.replay();

        expect(headersOf(1)).toMatchObject({ 'X-BB-Occurred-At': '2026-11-03T09:30:00.000Z' });
    });

    it('OFF-011 keeps an entry after a network failure or a 5xx and retries after 2 s, doubling up to 5 minutes', async () => {
        // setTimeout only: IndexedDB's own scheduling keeps running.
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
        let calls = 0;
        fetchMock.mockImplementation(async (url) => {
            if (!String(url).startsWith('/capabilities/')) throw new TypeError('offline');
            calls++;
            if (calls % 2 === 0) return json({ message: 'Bad gateway' }, 502);
            throw new TypeError('Failed to fetch');
        });

        await m.call.write('steps_log', { steps: 9000 }, { label });
        await vi.advanceTimersByTimeAsync(0);
        expect(calls).toBe(1);

        for (const wait of [2000, 4000, 8000, 16000, 32000, 64000, 128000, 256000, 300000, 300000]) {
            const before = calls;
            await vi.advanceTimersByTimeAsync(wait - 1);
            expect(calls).toBe(before);
            await vi.advanceTimersByTimeAsync(1);
            expect(calls).toBe(before + 1);
        }

        expect(m.outbox.entries.value).toHaveLength(1);
        expect(m.outbox.stateOf(m.outbox.entries.value[0]!)).toBe('waiting');
    });

    it.each([
        ['the throttle', json({ message: 'Too Many Attempts.' }, 429)],
        ['a gateway that gave up', json({ message: 'Bad gateway' }, 502)],
        ['the app still running the first attempt', json({ ok: false, error: { code: 'conflict', message: 'Still processing.', retryable: true } }, 409)],
    ])('OFF-011 keeps and retries an entry after %s, which is no answer to it', async (_, response) => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
        let calls = 0;
        fetchMock.mockImplementation(async (url) => {
            if (url !== '/capabilities/steps_log') throw new TypeError('offline');
            return ++calls === 1 ? response.clone() : ok();
        });

        await m.call.write('steps_log', { steps: 9000 }, { label });
        await vi.advanceTimersByTimeAsync(0);
        expect(m.outbox.entries.value.map((entry) => m.outbox.stateOf(entry))).toEqual(['waiting']);

        await vi.advanceTimersByTimeAsync(m.call.RETRY_FIRST_MS);
        expect(calls).toBe(2);
        await vi.waitFor(() => expect(m.outbox.entries.value).toEqual([]));
    });

    it('OFF-011 keeps an entry the app\'s rate limit refused before running it, and sends it again under the same key after retry_after', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
        let calls = 0;
        fetchMock.mockImplementation(async (url) => {
            if (url !== '/capabilities/steps_log') throw new TypeError('offline');
            return ++calls === 1 ? json({ ok: false, error: { code: 'rate_limited', message: 'Slow down.', retryable: true, retry_after: 5 } }, 429) : ok();
        });

        await m.call.write('steps_log', { steps: 9000 }, { label });
        await vi.advanceTimersByTimeAsync(0);
        expect(m.outbox.entries.value.map((entry) => m.outbox.stateOf(entry))).toEqual(['waiting']);

        await vi.advanceTimersByTimeAsync(4_999);
        expect(calls).toBe(1);
        await vi.advanceTimersByTimeAsync(1);
        expect(calls).toBe(2);
        expect(headersOf(1)['Idempotency-Key']).toBe(headersOf(0)['Idempotency-Key']);
        await vi.waitFor(() => expect(m.outbox.entries.value).toEqual([]));
    });

    it.each([
        ['an error the app answered with', 500, { ok: false, error: { code: 'internal', message: 'Something went wrong on our side.' } }],
        ['a limit the app hit after the write ran', 429, { ok: false, error: { code: 'rate_limited', message: 'Slow down.', retryable: false } }],
        ['a conflict the app keeps', 409, { ok: false, error: { code: 'conflict', message: 'That set was deleted.', retryable: false } }],
    ])("OFF-011 moves an entry to Couldn't sync after %s, since the server keeps that answer under its key", async (_, status, body) => {
        fetchMock.mockImplementation(async (url) => (url === '/capabilities/steps_log' ? json(body, status) : Promise.reject(new TypeError('offline'))));

        await m.call.write('steps_log', { steps: 9000 }, { label });
        await settle();

        const [entry] = m.outbox.entries.value;
        expect(m.outbox.stateOf(entry!)).toBe('failed');
        expect(entry!.refusal).toEqual({ status, body });
    });

    it("OFF-011 moves an entry to Couldn't sync once the server has said it is still running it ten times running", async () => {
        const busy = { ok: false, error: { code: 'conflict', message: 'Still processing.', retryable: true } };
        fetchMock.mockImplementation(async (url) => (url === '/capabilities/steps_log' ? json(busy, 409) : Promise.reject(new TypeError('offline'))));
        await m.call.write('steps_log', { steps: 9000 }, { label });
        await m.call.write('weigh_in_log', { weight: 80 }, { label });
        await settle();

        // Each pass stands for the backoff's next try; the two writes already sent some.
        const steps = () => sent().filter(([name]) => name === 'steps_log').length;
        for (let pass = steps(); pass < m.call.STUCK_AFTER - 1; pass++) await m.call.replay();
        expect(steps()).toBe(m.call.STUCK_AFTER - 1);
        expect(m.outbox.entries.value.map((entry) => m.outbox.stateOf(entry))).toEqual(['waiting', 'waiting']);

        await m.call.replay();

        expect(m.outbox.entries.value.map((entry) => [entry.name, m.outbox.stateOf(entry)])).toEqual([
            ['steps_log', 'failed'],
            ['weigh_in_log', 'waiting'],
        ]);
        expect(sent().filter(([name]) => name === 'steps_log')).toHaveLength(m.call.STUCK_AFTER);
        expect(sent().at(-1)?.[0]).toBe('weigh_in_log');
    });

    it("OFF-011 counts only the server's \"still running\" answers in a row: a try with no answer at all starts the count again", async () => {
        const busy = { ok: false, error: { code: 'conflict', message: 'Still processing.', retryable: true } };
        let tries = 0;
        fetchMock.mockImplementation(async (url) => {
            if (url !== '/capabilities/steps_log') throw new TypeError('offline');
            tries++;
            if (tries === m.call.STUCK_AFTER) throw new TypeError('Failed to fetch');
            return json(busy, 409);
        });
        await m.call.write('steps_log', { steps: 9000 }, { label });
        await settle();

        for (let pass = 1; pass < 2 * m.call.STUCK_AFTER - 1; pass++) await m.call.replay();
        // Nineteen tries: nine 409s, no answer, nine 409s.
        expect(tries).toBe(2 * m.call.STUCK_AFTER - 1);
        expect(m.outbox.entries.value.map((entry) => m.outbox.stateOf(entry))).toEqual(['waiting']);

        await m.call.replay();
        expect(m.outbox.entries.value.map((entry) => m.outbox.stateOf(entry))).toEqual(['failed']);
    });

    it('OFF-011 starts the backoff at 2 s again after an entry goes through', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
        let failing = true;
        fetchMock.mockImplementation(async (url) => {
            if (!String(url).startsWith('/capabilities/') || url === '/capabilities/sync_manifest') throw new TypeError('offline');
            if (failing) throw new TypeError('Failed to fetch');
            return ok();
        });
        await m.call.write('steps_log', { steps: 1 }, { label });
        await vi.advanceTimersByTimeAsync(2_000 + 4_000);
        failing = false;
        await vi.advanceTimersByTimeAsync(8_000);
        await vi.waitFor(() => expect(m.outbox.entries.value).toEqual([]));

        failing = true;
        await m.call.write('steps_log', { steps: 2 }, { label });
        await vi.advanceTimersByTimeAsync(0);
        const before = sent().length;
        await vi.advanceTimersByTimeAsync(1_999);
        expect(sent()).toHaveLength(before);
        await vi.advanceTimersByTimeAsync(1);
        expect(sent()).toHaveLength(before + 1);
    });

    it('OFF-011 retries at once when the device reconnects', async () => {
        const target = new EventTarget() as Window;
        m.call.keepSynced(target);
        await m.call.write('steps_log', { steps: 9000 }, { label });
        await settle();
        expect(m.outbox.entries.value).toHaveLength(1);

        fetchMock.mockImplementation(async () => ok());
        target.dispatchEvent(new Event('online'));
        await settle();

        expect(m.outbox.entries.value).toEqual([]);
        expect(sent().at(-1)).toEqual(['steps_log', { steps: 9000 }]);
    });

    it("OFF-011 moves a refused entry to Couldn't sync with the server's message, and replays the next", async () => {
        fetchMock.mockImplementation(async (url, init) => {
            if (!String(url).startsWith('/capabilities/')) throw new TypeError('offline');
            const body = JSON.parse(String(init?.body)) as { weight?: number };
            return body.weight === -5 ? refused(422, 'Enter a weight above zero.', 'weight') : ok();
        });

        await m.call.write('weigh_in_log', { weight: -5 }, { label });
        await m.call.write('steps_log', { steps: 9000 }, { label });
        await settle();

        expect(m.outbox.entries.value).toHaveLength(1);
        const [failed] = m.outbox.entries.value;
        expect(m.outbox.stateOf(failed!)).toBe('failed');
        expect(failed!.refusal).toMatchObject({ status: 422, body: { error: { message: 'Enter a weight above zero.' } } });
        expect(sent()).toEqual([
            ['weigh_in_log', { weight: -5 }],
            ['steps_log', { steps: 9000 }],
        ]);
    });

    it('OFF-011 holds back later entries that use a ULID the failed entry created, and what waits on them', async () => {
        fetchMock.mockImplementation(async (url) => {
            if (!String(url).startsWith('/capabilities/')) throw new TypeError('offline');
            return String(url).endsWith('/workout_set_add') ? refused(404, 'Not found.') : ok();
        });

        await m.call.write('workout_set_add', { exercise_id: 'e', set_id: '01NEWSET' }, { label, creates: ['01NEWSET'] });
        await m.call.write('workout_set_log', { set_id: '01NEWSET', reps: 8 }, { label, creates: ['01LOG'] });
        await m.call.write('workout_note_set', { log: { id: '01LOG' } }, { label });
        await m.call.write('steps_log', { steps: 9000 }, { label });
        await settle();

        expect(m.outbox.entries.value.map((entry) => [entry.name, m.outbox.stateOf(entry)])).toEqual([
            ['workout_set_add', 'failed'],
            ['workout_set_log', 'held'],
            ['workout_note_set', 'held'],
        ]);
        expect(sent().map(([name]) => name)).toEqual(['workout_set_add', 'steps_log']);
    });

    it('OFF-011 retries a failed entry with the input the user fixed, under a new idempotency key, in its place', async () => {
        let weight = -5;
        fetchMock.mockImplementation(async (url, init) => {
            if (url !== '/capabilities/weigh_in_log') throw new TypeError('offline');
            weight = (JSON.parse(String(init?.body)) as { weight: number }).weight;
            return weight < 0 ? refused(422, 'Enter a weight above zero.', 'weight') : ok();
        });
        await m.call.write('weigh_in_log', { weight: -5, unit: 'kg' }, { label });
        await settle();
        const [failed] = m.outbox.entries.value;

        await m.call.retryEntry(failed!, { ...failed!.input, weight: 80 });
        await settle();

        expect(weight).toBe(80);
        expect(headersOf(1)['Idempotency-Key']).not.toBe(headersOf(0)['Idempotency-Key']);
        expect(sent()[1]).toEqual(['weigh_in_log', { weight: 80, unit: 'kg' }]);
        expect(m.outbox.entries.value).toEqual([]);
    });

    it('OFF-011 sends a fixed entry in its place, before the entries that waited on it', async () => {
        let fixedIt = false;
        fetchMock.mockImplementation(async (url) => {
            if (!String(url).startsWith('/capabilities/') || url === '/capabilities/sync_manifest') throw new TypeError('offline');
            return url === '/capabilities/workout_set_add' && !fixedIt ? refused(422, 'Pick an exercise.', 'exercise_id') : ok();
        });
        await m.call.write('workout_set_add', { exercise_id: '', set_id: '01NEWSET' }, { label, creates: ['01NEWSET'] });
        await m.call.write('workout_set_log', { set_id: '01NEWSET', reps: 8 }, { label });
        await settle();
        expect(m.outbox.entries.value.map((entry) => m.outbox.stateOf(entry))).toEqual(['failed', 'held']);

        fixedIt = true;
        await m.call.retryEntry(m.outbox.entries.value[0]!, { exercise_id: 'e', set_id: '01NEWSET' });
        await settle();

        expect(sent().map(([name]) => name)).toEqual(['workout_set_add', 'workout_set_add', 'workout_set_log']);
        expect(m.outbox.entries.value).toEqual([]);
    });

    it('OFF-011 discards a failed entry, and sends what waited on it in its turn', async () => {
        fetchMock.mockImplementation(async (url) => {
            if (!String(url).startsWith('/capabilities/')) throw new TypeError('offline');
            return String(url).endsWith('/workout_set_add') ? refused(422, 'No such exercise.') : ok();
        });
        await m.call.write('workout_set_add', { set_id: '01NEWSET' }, { label, creates: ['01NEWSET'] });
        await m.call.write('workout_set_log', { set_id: '01NEWSET' }, { label });
        await settle();

        await m.call.discardEntry(m.outbox.entries.value[0]!);
        await settle();

        expect(sent().map(([name]) => name)).toEqual(['workout_set_add', 'workout_set_log']);
        expect(m.outbox.entries.value).toEqual([]);
    });

    it.each([401, 419])('OFF-012 keeps the outbox when the session is gone (%s), and replays it once that user signs in again', async (status) => {
        fetchMock.mockImplementation(async (url) => {
            if (!String(url).startsWith('/capabilities/')) throw new TypeError('offline');
            // The server clears the owner cookie with a gone session.
            signIn(null);
            return json({ message: 'Unauthenticated.' }, status);
        });
        await m.call.write('steps_log', { steps: 9000 }, { label });
        await settle();
        expect(m.outbox.othersWaiting.value).toBe(0);
        await m.outbox.loadOutbox();
        // Signed out, the entry is no one's on screen, and nothing retries it.
        expect(m.outbox.entries.value).toEqual([]);
        expect(m.outbox.othersWaiting.value).toBe(1);

        signIn('a1');
        fetchMock.mockImplementation(async (url) => (String(url).startsWith('/capabilities/') ? ok() : json({ ok: true, data: { capabilities: [] } })));
        await m.call.sync().catch(() => undefined);

        expect(sent().map(([name]) => name)).toEqual(['steps_log', 'steps_log']);
        expect(m.outbox.entries.value).toEqual([]);
        expect(m.outbox.othersWaiting.value).toBe(0);
    });

    // The device refuses every delete: the browser keeps the stored copy.
    function deletesFail(): void {
        vi.spyOn(IDBObjectStore.prototype, 'delete').mockImplementation(() => {
            throw new DOMException('The transaction was aborted.', 'AbortError');
        });
    }

    it('OFF-011 a discarded entry stays gone after a reload when the device could not delete it, and is never sent', async () => {
        fetchMock.mockImplementation(async (url) => (url === '/capabilities/weigh_in_log' ? refused(422, 'Enter a weight above zero.', 'weight') : Promise.reject(new TypeError('offline'))));
        await m.call.write('weigh_in_log', { weight: -5 }, { label });
        await settle();
        deletesFail();

        await m.call.discardEntry(m.outbox.entries.value[0]!);

        const reloaded = await boot();
        await reloaded.outbox.loadOutbox();
        expect(reloaded.outbox.entries.value).toEqual([]);
        fetchMock.mockClear();
        await reloaded.call.replay();
        expect(sent()).toEqual([]);
    });

    it("OFF-012 another account's changes stay deleted after a reload when the device could not delete them", async () => {
        await m.call.write('steps_log', { steps: 9000 }, { label });
        await settle();
        signIn('b2');
        await m.outbox.loadOutbox();
        deletesFail();

        await m.outbox.removeOthers();

        const reloaded = await boot();
        await reloaded.outbox.loadOutbox();
        expect(reloaded.outbox.othersWaiting.value).toBe(0);
        signIn('a1');
        await reloaded.outbox.loadOutbox();
        expect(reloaded.outbox.entries.value).toEqual([]);
    });

    it('OFF-011 deletes a removed entry from the device on the next load once deleting works again', async () => {
        await m.call.write('steps_log', { steps: 9000 }, { label });
        await settle();
        deletesFail();
        await m.outbox.removeEntries(m.outbox.entries.value.map((entry) => entry.seq));
        vi.restoreAllMocks();

        const reloaded = await boot();
        await reloaded.outbox.loadOutbox();

        // Stored nothing more, and remembers nothing more: the delete landed.
        const stored = await new Promise<unknown[]>((resolve) => {
            const open = indexedDB.open('bodybuilder-outbox', 1);
            open.onsuccess = () => {
                const request = open.result.transaction('entries').objectStore('entries').getAll();
                request.onsuccess = () => {
                    open.result.close();
                    resolve(request.result);
                };
            };
        });
        expect(stored).toEqual([]);
        expect(JSON.parse(localStorage.getItem('bb.outbox.removed') ?? '[]')).toEqual([]);
    });

    it("OFF-012 never sends another user's entries, and deletes them only when asked to", async () => {
        await m.call.write('steps_log', { steps: 9000 }, { label });
        await settle();
        signIn('b2');
        fetchMock.mockImplementation(async () => ok());
        await m.outbox.loadOutbox();

        expect(m.outbox.entries.value).toEqual([]);
        expect(m.outbox.othersWaiting.value).toBe(1);
        await m.call.replay();
        // Only a1's own attempt, from before the switch.
        expect(sent()).toEqual([['steps_log', { steps: 9000 }]]);

        await m.outbox.removeOthers();
        const reloaded = await boot();
        await reloaded.outbox.loadOutbox();
        expect(reloaded.outbox.othersWaiting.value).toBe(0);
    });

    it("OFF-008 marks a read pending while an entry that affects it waits, from the catalog's affects tags", async () => {
        fetchMock.mockImplementation(async (url) => {
            if (url === '/capabilities') {
                return json({ ok: true, data: { capabilities: [{ name: 'workout_set_log', tags: ['affects:workout_show', 'affects:today_show', 'reversible'] }, { name: 'undo_apply', tags: ['affects:*'] }] } });
            }
            throw new TypeError('offline');
        });
        await m.call.sync().catch(() => undefined);

        await m.call.write('workout_set_log', { set_id: 's' }, { label });

        expect(m.outbox.isPending('workout_show')).toBe(true);
        expect(m.outbox.isPending('today_show{"date":"2026-11-02"}')).toBe(true);
        expect(m.outbox.isPending('nutrition_show')).toBe(false);

        fetchMock.mockImplementation(async (url) => (String(url).startsWith('/capabilities/workout') ? refused(422, 'No.') : Promise.reject(new TypeError('offline'))));
        await m.call.replay();
        expect(m.outbox.isPending('workout_show')).toBe(false);
    });

    it("OFF-009 gives a row the user's own input while it waits to sync, newest first", async () => {
        await m.call.write('workout_set_log', { set_id: 'a', weight: 100, reps: 8 }, { label });
        await m.call.write('workout_set_log', { set_id: 'b', weight: 60, reps: 10 }, { label });
        await m.call.write('workout_set_log', { set_id: 'a', weight: 100, reps: 9 }, { label });

        const forRow = (id: string) => m.outbox.pendingInput<{ set_id: string; reps: number }>('workout_set_log', (input) => input.set_id === id);

        expect(forRow('a')).toEqual({ set_id: 'a', weight: 100, reps: 9 });
        expect(forRow('b')?.reps).toBe(10);
        expect(forRow('c')).toBeUndefined();
    });

    it('OFF-009 never shows input the server refused as if it were waiting', async () => {
        await m.call.write('workout_set_log', { set_id: 'a', reps: 8 }, { label });
        const newestForA = await m.call.write('workout_set_log', { set_id: 'a', reps: 99 }, { label });
        const onlyForB = await m.call.write('workout_set_log', { set_id: 'b', reps: 99 }, { label });
        const refusal = { status: 422, body: { ok: false, error: { message: 'Too many reps.' } } };
        await m.outbox.updateEntry({ ...newestForA, refusal });
        await m.outbox.updateEntry({ ...onlyForB, refusal });
        const forRow = (id: string) => m.outbox.pendingInput<{ set_id: string; reps: number }>('workout_set_log', (input) => input.set_id === id);

        expect(forRow('a')?.reps).toBe(8);
        expect(forRow('b')).toBeUndefined();
    });

    it('CAP-010 drops the cached reads a replayed answer invalidates, tells screens, and warms the cache after (OFF-004)', async () => {
        await m.cache.store('today_show{}', { sets: 1 });
        const told = vi.fn();
        m.outbox.onReplayed(told);
        fetchMock.mockImplementation(async (url) => {
            if (url === '/capabilities/workout_set_log') return ok({ invalidates: ['today_show{}'], toast: null, undo: null });
            if (url === '/capabilities/sync_manifest') return ok({ keys: [] });
            throw new TypeError('offline');
        });

        await m.call.write('workout_set_log', { set_id: 's' }, { label });
        await settle();

        expect(await m.cache.cached('today_show{}')).toBeUndefined();
        expect(told).toHaveBeenCalledTimes(1);
        expect(told).toHaveBeenCalledWith(['today_show{}']);
        expect(sent().map(([name]) => name)).toEqual(['workout_set_log']);
        expect(fetchMock.mock.calls.map(([url]) => url)).toContain('/capabilities/sync_manifest');
        expect(m.outbox.syncedAt.value).not.toBeNull();
    });

    it('CAP-010 tells screens once per replay, with every read the replayed answers invalidated', async () => {
        const told = vi.fn();
        m.outbox.onReplayed(told);
        let answer: (response: Response) => void = () => {};
        fetchMock.mockImplementationOnce((url) => {
            expect(url).toBe('/capabilities/workout_set_log');
            return new Promise<Response>((resolve) => (answer = resolve));
        });

        await m.call.write('workout_set_log', { set: 1 }, { label });
        await m.call.write('workout_set_log', { set: 2 }, { label });
        fetchMock.mockImplementation(async (url) => (url === '/capabilities/workout_set_log' ? ok({ invalidates: ['week_show{}'] }) : Promise.reject(new TypeError('offline'))));
        answer(ok({ invalidates: ['today_show{}'] }));
        await vi.waitFor(() => expect(m.outbox.entries.value).toEqual([]));
        await settle();

        expect(told).toHaveBeenCalledTimes(1);
        expect(told).toHaveBeenCalledWith(['today_show{}', 'week_show{}']);
    });

    it('CAP-010 tells screens what the answers so far invalidated when a replay stops partway', async () => {
        // Made offline, so both wait.
        await m.call.write('workout_set_log', { set: 1 }, { label });
        await m.call.write('workout_set_log', { set: 2 }, { label });
        await settle();
        const told = vi.fn();
        m.outbox.onReplayed(told);
        fetchMock.mockImplementation(async (url, init) => {
            if (url !== '/capabilities/workout_set_log') throw new TypeError('offline');
            if ((JSON.parse(String(init?.body)) as { set: number }).set === 1) return ok({ invalidates: ['today_show{}'] });
            throw new TypeError('Failed to fetch');
        });

        await m.call.replay();

        expect(m.outbox.entries.value.map((entry) => entry.input)).toEqual([{ set: 2 }]);
        expect(told).toHaveBeenCalledTimes(1);
        expect(told).toHaveBeenCalledWith(['today_show{}']);
    });

    it('ACC-003 a sync whose replay stopped does not count as synced', async () => {
        fetchMock.mockImplementation(async (url) => (url === '/capabilities/sync_manifest' ? ok({ keys: [] }) : Promise.reject(new TypeError('offline'))));
        await m.call.write('steps_log', { steps: 9000 }, { label });

        await m.call.sync();

        expect(m.outbox.syncedAt.value).toBeNull();
    });

    it('OFF-006 a write the user waits on is not sent until the entries made before it have answered', async () => {
        let answerSteps: (response: Response) => void = () => {};
        fetchMock.mockImplementation((url) => {
            if (url === '/capabilities/steps_log') return new Promise<Response>((resolve) => (answerSteps = resolve));
            if (url === '/capabilities/token_revoke') return Promise.resolve(ok({ invalidates: [] }));
            return Promise.reject(new TypeError('offline'));
        });

        await m.call.write('steps_log', { steps: 9000 }, { label });
        const revoked = m.call.writeNow('token_revoke', { id: 't' });
        await settle();
        expect(sent().map(([name]) => name)).toEqual(['steps_log']);

        answerSteps(ok());
        await revoked;
        expect(sent().map(([name]) => name)).toEqual(['steps_log', 'token_revoke']);
    });

    it.each([401, 419])('OFF-012 a write the user waits on fails with the gone session (%s) when the outbox stopped on it, without sending', async (status) => {
        fetchMock.mockImplementation(async (url) => (url === '/capabilities/steps_log' ? json({ message: 'Unauthenticated.' }, status) : Promise.reject(new TypeError('offline'))));
        await m.call.write('steps_log', { steps: 9000 }, { label });
        await settle();

        await expect(m.call.writeNow('token_revoke', { id: 't' })).rejects.toMatchObject({ capability: 'token_revoke', status });
        expect(sent().map(([name]) => name)).not.toContain('token_revoke');
    });

    it("OFF-031 a write the user waits on fails at once without sending while earlier entries can't be sent", async () => {
        fetchMock.mockImplementation(async (url) => (url === '/capabilities/steps_log' ? json({ message: 'Bad gateway' }, 502) : Promise.reject(new TypeError('offline'))));
        await m.call.write('steps_log', { steps: 9000 }, { label });
        await settle();

        await expect(m.call.writeNow('token_revoke', { id: 't' })).rejects.toMatchObject({ capability: 'token_revoke', status: 0, body: { reason: 'waiting', offline: false } });
        expect(sent().map(([name]) => name)).not.toContain('token_revoke');
    });

    it('OFF-031 says the device is offline when that is why earlier entries could not be sent', async () => {
        await m.call.write('steps_log', { steps: 9000 }, { label });
        await settle();

        await expect(m.call.writeNow('token_revoke', { id: 't' })).rejects.toMatchObject({ status: 0, body: { reason: 'waiting', offline: true } });
    });

    it('OFF-006 refuses a write with no one signed in, rather than keep it for no one', async () => {
        signIn(null);

        await expect(m.call.write('steps_log', { steps: 1 }, { label })).rejects.toMatchObject({ status: 401 });
        expect(m.outbox.entries.value).toEqual([]);
    });
});
