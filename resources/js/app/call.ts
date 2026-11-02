import type { SyncManifestOutput } from '@/contracts/sync_manifest';
import { cached, clearReads, drop, evict, isCurrent, parseReadKey, readKey, requestPersistentStorage, stamp, store } from './cache';
import { isWalked, now } from './clock';

// The only way the app talks to the server (CAP-004): POST /capabilities/{name}
// with Sanctum SPA cookie auth, plus X-BB-Now while the clock is walked (SET-011).
// read() goes through the IndexedDB cache (OFF-003), and OUTBOX (#58) puts write()
// behind the outbox.

export type CapabilityInput = Record<string, unknown>;

// How many manifest reads a warm sends at once (OFF-004).
export const WARM_CONCURRENCY = 4;

// A warm skips a read cached this recently, so a flapping connection doesn't refetch
// everything on each `online`. A write drops the reads it changes, so after a replay
// those are missing and fetched again whatever their age.
export const WARM_FRESH_MS = 60_000;

// One deadline per call, CSRF cookie fetch and response body included, so a
// stalled server ends in a CapabilityError instead of a screen stuck on loading.
export const TIMEOUT_MS = 15_000;

export class CapabilityError extends Error {
    constructor(
        readonly capability: string,
        readonly status: number,
        readonly body: unknown,
    ) {
        super(`${capability} failed with status ${status}`);
        this.name = 'CapabilityError';
    }
}

/**
 * Stale-while-revalidate (OFF-003). With a cached copy, it resolves with that at
 * once, refreshes it in the background and hands the fresh copy to onUpdate.
 * Without one, it waits for the server; offline, that is a CapabilityError with status 0.
 * A refresh that fails because the network or the server failed keeps the cached copy.
 * One that answers 401 or 419 clears the whole cache (forget()), and one that answers
 * 403 or 404 drops this read; either way onError gets the error, so the screen can stop
 * showing the copy it was given.
 */
export async function read<T = unknown>(
    name: string,
    input: CapabilityInput = {},
    onUpdate?: (data: T) => void,
    onError?: (error: CapabilityError) => void,
): Promise<T> {
    const key = readKey(name, input);
    const hit = await cached(key).catch(() => undefined);
    const fresh = fetchRead<T>(name, input, key);

    if (hit === undefined) return fresh;

    fresh.then(onUpdate, (error: unknown) => {
        if (hasStatus(error, REMOVES_COPY)) onError?.(error);
        // Otherwise keep showing the cached copy.
    });

    return hit.data as T;
}

// The statuses after which a cached copy may not be shown. A gone session clears
// every read (forget()); a refused or missing read drops that one (fetchRead()).
const SESSION_GONE = [401, 419];
const READ_GONE = [403, 404];
const REMOVES_COPY = [...SESSION_GONE, ...READ_GONE];

function hasStatus(error: unknown, statuses: readonly number[]): error is CapabilityError {
    return error instanceof CapabilityError && statuses.includes(error.status);
}

/**
 * OFF-004: fetch every read key the server's sync_manifest lists into the cache,
 * then drop what OFF-005 says to. Persistent storage is asked for once the
 * manifest lists something, which is after onboarding (OFF-005).
 * A call while a warm runs queues one more warm after it, so a reconnect or a
 * replay is never answered by a fetch that started before it, and warms never pile up.
 */
export function warm(): Promise<void> {
    if (warming === undefined) {
        warming = warmOnce().finally(() => (warming = undefined));

        return warming;
    }

    queued ??= warming
        .catch(() => undefined)
        .then(() => {
            queued = undefined;

            return warm();
        });

    return queued;
}

let warming: Promise<void> | undefined;
let queued: Promise<void> | undefined;

async function warmOnce(): Promise<void> {
    // The schema can't type array items as strings, so the contract says unknown[].
    const keys = (await invoke<SyncManifestOutput>('sync_manifest', {})).keys.filter((key) => typeof key === 'string');

    await inBatches(keys, WARM_CONCURRENCY, async (key) => {
        const hit = await cached(key).catch(() => undefined);
        if (hit !== undefined && Date.now() - hit.fetchedAt < WARM_FRESH_MS) return;

        const parsed = parseReadKey(key);
        // A malformed key fails on its own; the rest still warm.
        if (parsed === undefined) throw new Error(`Not a read key: ${key}`);

        await fetchRead(parsed.name, parsed.input, key);
    });
    await evict(new Set(keys));

    if (keys.length > 0) await requestPersistentStorage();
}

/** Runs task over items, at most `limit` at a time, and waits for all of them whether they fail or not. */
async function inBatches<T>(items: readonly T[], limit: number, task: (item: T) => Promise<void>): Promise<void> {
    let next = 0;
    const worker = async () => {
        while (next < items.length) {
            await task(items[next++]!).catch(() => {
                // One read failing doesn't stop the others.
            });
        }
    };

    await Promise.all(Array.from({ length: Math.min(limit, items.length) }, worker));
}

/** Warm the cache now and on every reconnect (OFF-004). OUTBOX warms it after each replay. */
export function keepWarm(target: Window = window): void {
    const run = () => {
        warm().catch(() => {
            // Signed out or offline: the next open or reconnect tries again.
        });
    };

    run();
    target.addEventListener('online', run);
}

async function fetchRead<T>(name: string, input: CapabilityInput, key: string): Promise<T> {
    const since = stamp();
    const data = await invoke<T>(name, input).catch(async (error: unknown) => {
        if (hasStatus(error, READ_GONE)) await drop([key]).catch(() => undefined);

        throw error;
    });
    // A write, a clear or a new owner came while this was in flight, so the answer
    // may predate it: ask again rather than show or store it.
    if (!isCurrent(key, since)) return fetchRead(name, input, key);

    await store(key, data).catch(() => {
        // No IndexedDB (a private window, say): the read still answers.
    });

    return data;
}

/**
 * A write drops the cached reads its WriteResult invalidates (CAP-010), so the next read of them waits for the server.
 * With an idempotency key, a retry after a lost answer returns the first result instead of running again (CAP-014).
 */
export async function write<T = unknown>(name: string, input: CapabilityInput = {}, options: { idempotencyKey?: string } = {}): Promise<T> {
    const data = await invoke<T>(name, input, options.idempotencyKey);
    const invalidates = (data as { invalidates?: unknown } | null)?.invalidates;

    if (Array.isArray(invalidates)) {
        await drop(invalidates.filter((key) => typeof key === 'string')).catch(() => undefined);
    }

    return data;
}

async function invoke<T>(name: string, input: CapabilityInput, idempotencyKey?: string): Promise<T> {
    const deadline = new AbortController();
    const timer = setTimeout(() => deadline.abort(), TIMEOUT_MS);

    try {
        return await send<T>(name, input, deadline.signal, idempotencyKey);
    } catch (error) {
        await forget(error);
        throw error;
    } finally {
        clearTimeout(timer);
    }
}

// 401 (signed out) and 419 (session expired): none of the cached reads may be shown
// again. The server also drops the bb_cache_owner cookie then, as on sign-out, and
// a sign-in names the new owner, so cache.ts opens no other user's reads.
async function forget(error: unknown): Promise<void> {
    if (hasStatus(error, SESSION_GONE)) await clearReads().catch(() => undefined);
}

async function send<T>(name: string, input: CapabilityInput, signal: AbortSignal, idempotencyKey?: string): Promise<T> {
    let response: Response;

    try {
        response = await fetch(`/capabilities/${encodeURIComponent(name)}`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': await csrfToken(signal),
                ...(isWalked() ? { 'X-BB-Now': now().toISOString() } : {}),
                ...(idempotencyKey === undefined ? {} : { 'Idempotency-Key': idempotencyKey }),
            },
            body: JSON.stringify(input),
            signal,
        });
    } catch {
        // Status 0: no response arrived, because the deadline passed or the network failed.
        throw new CapabilityError(name, 0, { reason: signal.aborted ? 'timeout' : 'network' });
    }

    const body: unknown = await response.json().catch(() => null);

    if (!response.ok || !isSuccess(body)) {
        throw new CapabilityError(name, response.status, body);
    }

    return body.data as T;
}

function isSuccess(body: unknown): body is { ok: true; data: unknown } {
    return typeof body === 'object' && body !== null && (body as { ok?: unknown }).ok === true;
}

async function csrfToken(signal: AbortSignal): Promise<string> {
    let token = cookie('XSRF-TOKEN');

    if (token === null) {
        await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin', signal });
        token = cookie('XSRF-TOKEN');
    }

    return token ?? '';
}

function cookie(name: string): string | null {
    const match = document.cookie.split('; ').find((part) => part.startsWith(`${name}=`));

    return match === undefined ? null : decodeURIComponent(match.slice(name.length + 1));
}
