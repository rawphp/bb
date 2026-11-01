// The only way the app talks to the server (CAP-004): POST /capabilities/{name}
// with Sanctum SPA cookie auth. CACHE (#18) puts read() behind the IndexedDB
// cache, and OUTBOX (#58) puts write() behind the outbox.

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

export function read<T = unknown>(name: string, input: CapabilityInput = {}): Promise<T> {
    return invoke<T>(name, input);
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
