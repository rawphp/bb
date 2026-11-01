// The only way the app talks to the server (CAP-004): POST /capabilities/{name}
// with Sanctum SPA cookie auth. CACHE (#18) puts read() behind the IndexedDB
// cache, and OUTBOX (#58) puts write() behind the outbox.

export type CapabilityInput = Record<string, unknown>;

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
    const response = await fetch(`/capabilities/${encodeURIComponent(name)}`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': await csrfToken(),
        },
        body: JSON.stringify(input),
    });

    const body: unknown = await response.json().catch(() => null);

    if (!response.ok || !isSuccess(body)) {
        throw new CapabilityError(name, response.status, body);
    }

    return body.data as T;
}

function isSuccess(body: unknown): body is { ok: true; data: unknown } {
    return typeof body === 'object' && body !== null && (body as { ok?: unknown }).ok === true;
}

async function csrfToken(): Promise<string> {
    let token = cookie('XSRF-TOKEN');

    if (token === null) {
        await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' });
        token = cookie('XSRF-TOKEN');
    }

    return token ?? '';
}

function cookie(name: string): string | null {
    const match = document.cookie.split('; ').find((part) => part.startsWith(`${name}=`));

    return match === undefined ? null : decodeURIComponent(match.slice(name.length + 1));
}
