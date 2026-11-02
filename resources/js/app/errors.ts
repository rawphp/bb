import { CapabilityError } from './call';

/**
 * The server's own words for a failed call (CAP-012), else the fallback.
 * With no answer (status 0) there are none: offline, it says the call needs a
 * connection; past the deadline, the server may have done it, so it says so.
 */
export function failureMessage(error: unknown, fallback: string): string {
    if (!(error instanceof CapabilityError)) return fallback;
    if (isTimeout(error)) return 'The server did not answer in time, so this may have worked. Check, then try again.';
    if (error.status === 0) return 'No connection. Try again when you are online.';

    const message = (error.body as { error?: { message?: unknown } } | null)?.error?.message;

    return typeof message === 'string' && message !== '' ? message : fallback;
}

/** The server answered and refused the call (4xx), so it did nothing. A 5xx or no answer may have done it (OFF-011). */
export function isRefusal(error: unknown): boolean {
    return error instanceof CapabilityError && error.status >= 400 && error.status < 500;
}

/** No answer before the deadline (call.ts TIMEOUT_MS): the server may still have done the write. */
export function isTimeout(error: unknown): boolean {
    return error instanceof CapabilityError && error.status === 0 && (error.body as { reason?: unknown } | null)?.reason === 'timeout';
}
