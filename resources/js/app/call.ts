import type { SyncManifestOutput } from '@/contracts/sync_manifest';
import { cached, evict, readKey, requestPersistentStorage, store } from './cache';

// The only way the app talks to the server (CAP-004): POST /capabilities/{name}
// with Sanctum SPA cookie auth. read() goes through the IndexedDB cache (OFF-003),
// and OUTBOX (#58) puts write() behind the outbox.

export type CapabilityInput = Record<string, unknown>;

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
 */
export async function read<T = unknown>(name: string, input: CapabilityInput = {}, onUpdate?: (data: T) => void): Promise<T> {
    const key = readKey(name, input);
    const hit = await cached(key).catch(() => undefined);
    const fresh = fetchRead<T>(name, input, key);

    if (hit === undefined) return fresh;

    fresh.then(onUpdate, () => {
        // Keep showing the cached copy.
    });

    return hit.data as T;
}

/**
 * OFF-004: fetch every read key the server's sync_manifest lists into the cache,
 * then drop what OFF-005 says to. Persistent storage is asked for once the
 * manifest lists something, which is after onboarding (OFF-005).
 */
export async function warm(): Promise<void> {
    // The schema can't type array items as strings, so the contract says unknown[].
    const keys = (await invoke<SyncManifestOutput>('sync_manifest', {})).keys.filter((key) => typeof key === 'string');

    await Promise.allSettled(
        keys.map((key) => {
            const brace = key.indexOf('{');

            return fetchRead(key.slice(0, brace), JSON.parse(key.slice(brace)) as CapabilityInput, key);
        }),
    );
    await evict(new Set(keys));

    if (keys.length > 0) await requestPersistentStorage();
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
    const data = await invoke<T>(name, input);
    await store(key, data).catch(() => {
        // No IndexedDB (a private window, say): the read still answers.
    });

    return data;
}

export function write<T = unknown>(name: string, input: CapabilityInput = {}): Promise<T> {
    return invoke<T>(name, input);
}

async function invoke<T>(name: string, input: CapabilityInput): Promise<T> {
    const deadline = new AbortController();
    const timer = setTimeout(() => deadline.abort(), TIMEOUT_MS);

    try {
        return await send<T>(name, input, deadline.signal);
    } finally {
        clearTimeout(timer);
    }
}

async function send<T>(name: string, input: CapabilityInput, signal: AbortSignal): Promise<T> {
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
