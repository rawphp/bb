import type { SyncManifestOutput } from '@/contracts/sync_manifest';
import { cached, clearReads, drop, evict, isCurrent, owner, parseReadKey, readKey, requestPersistentStorage, stamp, store } from './cache';
import { isWalked, now } from './clock';
import {
    addEntry,
    affectsOf,
    announceReplayed,
    followSignedInUser,
    loadOutbox,
    markSynced,
    nextToSend,
    rememberAffects,
    removeEntries,
    updateEntry,
    type OutboxEntry,
} from './outbox';
import { ulid } from './ulid';

// The only way the app talks to the server (CAP-004): POST /capabilities/{name}
// with Sanctum SPA cookie auth, plus X-BB-Now while the clock is walked (SET-011).
// read() goes through the IndexedDB cache (OFF-003), and write() through the
// outbox (OFF-006).

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

// The deadline for a read that waits on the server's AI call (recipe_generate,
// recipe_import): longer than the 60 s AiJson gives the AI call itself, so the device does
// not give up on an answer the server is still working out. Import fetches the page first,
// so a slow page and a slow AI call together can still pass it.
export const AI_TIMEOUT_MS = 75_000;

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

/**
 * The server's answer only, never the cached copy, for a decision that must not rest on
 * an old one (MESO-007: whether a draft would be replaced). The answer is cached as
 * read() caches it. Offline it fails with status 0 and the cached copy stays.
 */
export function readFresh<T = unknown>(name: string, input: CapabilityInput = {}, options: { timeoutMs?: number } = {}): Promise<T> {
    return fetchRead<T>(name, input, readKey(name, input), options.timeoutMs);
}

// The statuses after which a cached copy may not be shown. A gone session clears
// every read (forget()); a refused or missing read drops that one (fetchRead()).
export const SESSION_GONE = [401, 419];
const READ_GONE = [403, 404];
const REMOVES_COPY = [...SESSION_GONE, ...READ_GONE];

/** A failed read after which the screen may not keep showing what it read before. */
export function removesCopy(error: unknown): boolean {
    return hasStatus(error, REMOVES_COPY);
}

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

/**
 * Open, reconnect and sign-in: read the outbox, learn what each write affects, replay
 * it, then warm the cache (OFF-004, OFF-006). Rejects when the warm does (signed out,
 * offline); the outbox has done what it can by then.
 */
export async function sync(): Promise<void> {
    await loadOutbox();
    await learnAffects();
    const stalled = await replay();
    await warm();
    if (stalled === undefined) markSynced();
}

/** Sync now and on every reconnect, which also retries the outbox at once (OFF-004, OFF-011). */
export function keepSynced(target: Window = window): void {
    const run = () => {
        sync().catch(() => {
            // Signed out or offline: the next open or reconnect tries again.
        });
    };

    run();
    target.addEventListener('online', run);
}

let affectsLearned = false;

/**
 * Each write's `affects`, from the catalog's `affects:<read>` tags (CAP-011), so an
 * entry can mark its reads pending before the server answers (OFF-008). Once a page
 * load: the catalog changes only with a deploy, which reloads the app.
 */
async function learnAffects(): Promise<void> {
    if (affectsLearned) return;
    const deadline = new AbortController();
    const timer = setTimeout(() => deadline.abort(), TIMEOUT_MS);

    try {
        const response = await fetch('/capabilities', { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: deadline.signal });
        if (!response.ok) return;
        const body = (await response.json()) as { data?: { capabilities?: { name?: unknown; tags?: unknown }[] } };
        const byName: Record<string, string[]> = {};
        for (const { name, tags } of body.data?.capabilities ?? []) {
            if (typeof name !== 'string' || !Array.isArray(tags)) continue;
            const reads = tags.filter((tag): tag is string => typeof tag === 'string' && tag.startsWith('affects:')).map((tag) => tag.slice('affects:'.length));
            if (reads.length > 0) byName[name] = reads;
        }
        rememberAffects(byName);
        affectsLearned = true;
    } catch {
        // Offline or signed out: entries use what was learned before.
    } finally {
        clearTimeout(timer);
    }
}

async function fetchRead<T>(name: string, input: CapabilityInput, key: string, timeoutMs = TIMEOUT_MS): Promise<T> {
    const since = stamp();
    const data = await invoke<T>(name, input, {}, timeoutMs).catch(async (error: unknown) => {
        if (hasStatus(error, READ_GONE)) await drop([key]).catch(() => undefined);

        throw error;
    });
    // A write, a clear or a new owner came while this was in flight, so the answer
    // may predate it: ask again rather than show or store it.
    if (!isCurrent(key, since)) return fetchRead(name, input, key, timeoutMs);

    await store(key, data).catch(() => {
        // No IndexedDB (a private window, say): the read still answers.
    });

    return data;
}

export type WriteOptions = {
    /** What the write does, in plain words, for the outbox list (OFF-010). */
    label: string;
    /** The ULIDs of the rows it creates (CAP-019). */
    creates?: string[];
};

/**
 * OFF-006: every write goes into the outbox, online or not, so there is one path. The
 * entry holds the input, a new idempotency key (CAP-014), the ULIDs it creates
 * (CAP-019), when the user acted (OFF-007) and who they are (OFF-012). It resolves once
 * the entry is stored, and replay sends it in its turn. Until the server answers, the
 * reads it affects are pending (outbox.isPending, OFF-008) and the screen shows the
 * user's own input on its row (outbox.pendingInput, OFF-009).
 */
export async function write(name: string, input: CapabilityInput, options: WriteOptions): Promise<OutboxEntry> {
    const user = owner();
    if (user === null) throw new CapabilityError(name, 401, null);

    const entry = await addEntry({
        user,
        name,
        input,
        idempotencyKey: newKey(name),
        creates: options.creates ?? [],
        occurredAt: now().toISOString(),
        label: options.label,
        affects: affectsOf(name),
    });
    void replay();

    return entry;
}

/**
 * A write the user waits on, for what needs the network and says so (OFF-031): token
 * and account changes, settings, undo. It takes its turn behind the outbox, so it never
 * overtakes a write made before it, but it is never kept: with no answer it fails at
 * once, and when earlier entries can't be sent it fails without sending (status 0,
 * reason `waiting`, `offline` when no answer came for them). It resolves with the server's answer and drops the cached reads
 * that answer invalidates (CAP-010). Pass the idempotency key of a retry after no
 * answer, so the server replays the first attempt rather than running it twice (CAP-014).
 */
export async function writeNow<T = unknown>(name: string, input: CapabilityInput = {}, options: { idempotencyKey?: string } = {}): Promise<T> {
    const stalled = await replay();
    if (stalled !== undefined) {
        // Signed out stays signed out; anything else is the outbox still waiting, and
        // this write was not sent, so it can't have run (unanswered() says no).
        throw SESSION_GONE.includes(stalled.status)
            ? new CapabilityError(name, stalled.status, stalled.body)
            : new CapabilityError(name, 0, { reason: 'waiting', offline: stalled.status === 0 });
    }

    const data = await invoke<T>(name, input, { 'Idempotency-Key': options.idempotencyKey ?? newKey(name) });
    await drop(invalidatesOf(data)).catch(() => undefined);

    return data;
}

function newKey(name: string): string {
    return `${name}:${ulid()}`;
}

function invalidatesOf(data: unknown): string[] {
    const invalidates = (data as { invalidates?: unknown } | null)?.invalidates;

    return Array.isArray(invalidates) ? invalidates.filter((key) => typeof key === 'string') : [];
}

// OFF-011: an entry with no answer tries again in 2 s, then doubling up to 5 minutes
// (or later, when a limit says how long to wait), and at once on reconnect (keepSynced).
export const RETRY_FIRST_MS = 2_000;
export const RETRY_MAX_MS = 5 * 60_000;
// The app saying "still running it" this many times in a row means the run that held
// the key died: the key will answer nothing else, so the entry couldn't sync.
export const STUCK_AFTER = 10;

let replaying: Promise<CapabilityError | undefined> | undefined;
let retries = 0;
let retryTimer: ReturnType<typeof setTimeout> | undefined;
const stillRunning = new Map<number, number>();

/**
 * Send the signed-in user's entries one at a time, oldest first (OFF-006). Resolves with
 * undefined once nothing is left to send, or with the error that stopped it: no answer
 * (retried with backoff), or a gone session (401, 419), after which the outbox waits for
 * that user to sign in again (OFF-012). Any other answer from the app is final, so the
 * entry moves to "Couldn't sync" and the replay goes on (OFF-011). A call while it runs
 * joins it; the loop picks up entries added meanwhile. Once it ends, screens hear every
 * read the answers invalidated, and, after it sent anything, the cache warms (OFF-004).
 */
export function replay(): Promise<CapabilityError | undefined> {
    replaying ??= replayAll();

    return replaying;
}

async function replayAll(): Promise<CapabilityError | undefined> {
    // Never settle synchronously, so `replaying` is set before the loop clears it.
    await Promise.resolve();
    clearTimeout(retryTimer);
    retryTimer = undefined;
    followSignedInUser();
    const invalidated: string[] = [];
    let sent = false;

    try {
        for (;;) {
            const entry = nextToSend();
            if (entry === undefined) {
                if (sent) markSynced();
                return undefined;
            }
            const stalled = await deliver(entry, invalidated);
            if (stalled !== undefined) return stalled;
            sent = true;
        }
    } finally {
        // Cleared in the same step the loop ends, so an entry added after it starts a new one.
        replaying = undefined;
        if (invalidated.length > 0) announceReplayed([...new Set(invalidated)]);
        if (sent) {
            warm().catch(() => {
                // Offline or signed out: the next sync warms again.
            });
        }
    }
}

/** Sends one entry, and resolves with the error that must stop the replay, if any. */
async function deliver(entry: OutboxEntry, invalidated: string[]): Promise<CapabilityError | undefined> {
    try {
        const data = await invoke(entry.name, entry.input, { 'Idempotency-Key': entry.idempotencyKey, 'X-BB-Occurred-At': entry.occurredAt });
        retries = 0;
        stillRunning.delete(entry.seq);
        const keys = invalidatesOf(data);
        await drop(keys).catch(() => undefined);
        await removeEntries([entry.seq]);
        invalidated.push(...keys);

        return undefined;
    } catch (failure) {
        const error = failure instanceof CapabilityError ? failure : new CapabilityError(entry.name, 0, { reason: 'network' });
        if (SESSION_GONE.includes(error.status)) return error;
        if (retryable(error) && !stuck(entry, error)) {
            retryLater(retryAfterMs(error));
            return error;
        }
        stillRunning.delete(entry.seq);
        await updateEntry({ ...entry, refusal: { status: error.status, body: error.body } });

        return undefined;
    }
}

// No answer to this entry yet: no response, a gateway's 5xx, the app still running it,
// or a limit that refused it before it ran. Any other answer is final: the same write
// sent again gets it again, replayed from the key or checked again (CAP-014).
function retryable(error: CapabilityError): boolean {
    return unanswered(error) || limitedBeforeRun(error);
}

/**
 * A 429 from a limit that refused the write before it ran: the throttle in front of the
 * app, or the app's own limit, which marks that retryable and gives the key back. Its
 * key is still good: sent again, it runs once the limit allows, or replays what an
 * earlier attempt under it stored (CAP-014).
 */
export function limitedBeforeRun(error: unknown): boolean {
    if (!(error instanceof CapabilityError) || error.status !== 429) return false;
    const app = appError(error);

    return app === null || app.retryable === true;
}

/** How long a limit asked the app to wait, if it said (`retry_after` seconds). */
function retryAfterMs(error: CapabilityError): number {
    const seconds = (appError(error) as { retry_after?: unknown } | null)?.retry_after;

    return typeof seconds === 'number' && seconds > 0 ? seconds * 1000 : 0;
}

function stuck(entry: OutboxEntry, error: CapabilityError): boolean {
    if (error.status !== 409) {
        stillRunning.delete(entry.seq);
        return false;
    }
    const times = (stillRunning.get(entry.seq) ?? 0) + 1;
    stillRunning.set(entry.seq, times);

    return times >= STUCK_AFTER;
}

function retryLater(atLeastMs = 0): void {
    const delay = Math.max(atLeastMs, Math.min(RETRY_FIRST_MS * 2 ** retries, RETRY_MAX_MS));
    retries++;
    retryTimer = setTimeout(() => void replay(), delay);
}

/** The app's own error envelope in a failed answer, or null when something in front of the app answered. */
function appError(error: CapabilityError): { retryable?: unknown } | null {
    const envelope = (error.body as { error?: unknown } | null)?.error;

    return typeof envelope === 'object' && envelope !== null ? (envelope as { retryable?: unknown }) : null;
}

/**
 * Whether an attempt ended without the app's final answer, so it may have run or may
 * still be running: no response at all (status 0); a 5xx without the app's error
 * envelope, which a proxy sends when it gives up while the app carries on; or the app's
 * own "already processing" 409, which it marks retryable (CAP-014, D-005). A write the
 * outbox held back (reason `waiting`) was never sent, so it has an answer: it didn't run.
 */
export function unanswered(error: unknown): boolean {
    if (!(error instanceof CapabilityError)) return false;
    if (error.status === 0) return (error.body as { reason?: unknown } | null)?.reason !== 'waiting';
    if (error.status >= 500) return appError(error) === null;

    return error.status === 409 && appError(error)?.retryable === true;
}

/**
 * OFF-011: send a failed entry again in its place, with the input the user fixed, if
 * any. It gets a new idempotency key, since the server keeps the refusal under the old one.
 */
export async function retryEntry(entry: OutboxEntry, input: CapabilityInput = entry.input): Promise<void> {
    const { refusal: _, ...rest } = entry;
    await updateEntry({ ...rest, input, idempotencyKey: newKey(entry.name) });
    void replay();
}

/** OFF-011: drop a failed entry. Entries that waited on it are sent in their turn. */
export async function discardEntry(entry: OutboxEntry): Promise<void> {
    await removeEntries([entry.seq]);
    void replay();
}

async function invoke<T>(name: string, input: CapabilityInput, headers: Record<string, string> = {}, timeoutMs = TIMEOUT_MS): Promise<T> {
    const deadline = new AbortController();
    const timer = setTimeout(() => deadline.abort(), timeoutMs);

    try {
        return await send<T>(name, input, deadline.signal, headers);
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

async function send<T>(name: string, input: CapabilityInput, signal: AbortSignal, headers: Record<string, string>): Promise<T> {
    const response = await post(name, `/capabilities/${encodeURIComponent(name)}`, input, signal, {
        headers: { ...(isWalked() ? { 'X-BB-Now': now().toISOString() } : {}), ...headers },
    });
    const body: unknown = await response.json().catch(() => null);

    if (!response.ok || !isSuccess(body)) {
        throw new CapabilityError(name, response.status, body);
    }

    return body.data as T;
}

type PostOptions = { headers?: Record<string, string>; freshCsrf?: boolean; redirect?: RequestRedirect };

/**
 * One JSON POST with Sanctum's CSRF header, shared by capabilities and host routes.
 * No answer is status 0: the deadline passed or the network failed.
 */
async function post(label: string, url: string, input: CapabilityInput, signal: AbortSignal, options: PostOptions = {}): Promise<Response> {
    try {
        return await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': await csrfToken(signal, options.freshCsrf),
                ...options.headers,
            },
            body: JSON.stringify(input),
            signal,
            ...(options.redirect === undefined ? {} : { redirect: options.redirect }),
        });
    } catch {
        throw new CapabilityError(label, 0, { reason: signal.aborted ? 'timeout' : 'network' });
    }
}

function isSuccess(body: unknown): body is { ok: true; data: unknown } {
    return typeof body === 'object' && body !== null && (body as { ok?: unknown }).ok === true;
}

/** The XSRF-TOKEN cookie, fetched first when missing, or always when `fresh`. */
async function csrfToken(signal: AbortSignal, fresh = false): Promise<string> {
    let token = fresh ? null : cookie('XSRF-TOKEN');

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

/**
 * POST to one of AUTH's host routes (CAP-004): /sign-in, /register, the code routes and
 * /forgot-password. They sit outside the capability rail, so they answer Laravel's own
 * shape: 204, or 422 with `errors` per field, or 429 from the throttle (app/hostFailure.ts
 * reads those). A sign-in screen left open outlives its session, so the CSRF cookie is
 * fetched fresh. No X-BB-Now: the walked clock applies to capabilities only.
 *
 * The routes sit behind `guest`, which redirects a session that is already signed in.
 * That redirect is not followed: it is a refusal (status 302, reason signed_in), never a
 * sign-in, so nobody lands in an account that is not theirs.
 */
export async function postHost(path: string, input: CapabilityInput): Promise<void> {
    const deadline = new AbortController();
    const timer = setTimeout(() => deadline.abort(), TIMEOUT_MS);

    try {
        const response = await post(path, path, input, deadline.signal, { freshCsrf: true, redirect: 'manual' });
        if (response.type === 'opaqueredirect') throw new CapabilityError(path, 302, { reason: 'signed_in' });
        // The body is read before the deadline is cleared, so a stalled body can't hang the form.
        if (!response.ok) throw new CapabilityError(path, response.status, await response.json().catch(() => null));
    } finally {
        clearTimeout(timer);
    }
}
