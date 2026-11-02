import { CapabilityError } from './call';

/**
 * The server's own words for a failed call (CAP-012), else the fallback.
 * Offline (status 0) there are none, so it says the call needs a connection.
 */
export function failureMessage(error: unknown, fallback: string): string {
    if (!(error instanceof CapabilityError)) return fallback;
    if (error.status === 0) return 'No connection. Try again when you are online.';

    const message = (error.body as { error?: { message?: unknown } } | null)?.error?.message;

    return typeof message === 'string' && message !== '' ? message : fallback;
}
