import { CapabilityError } from './call';
import { unanswered } from './retryKey';

/**
 * The server's own words for a failed call (CAP-012), else the fallback.
 * Offline (status 0) there are none, so it says the call needs a connection, or that
 * the write waits behind earlier changes (OFF-031).
 */
export function failureMessage(error: unknown, fallback: string): string {
    if (!(error instanceof CapabilityError)) return fallback;
    if (error.status === 0) {
        // call.ts writeNow(): not sent, because entries made before it can't be sent yet.
        const body = error.body as { reason?: unknown; offline?: unknown } | null;
        if (body?.reason === 'waiting' && body.offline !== true) return "Earlier changes haven't synced yet. Try again once they have.";
        return 'No connection. Try again when you are online.';
    }

    const body = (error.body as { error?: { code?: unknown; message?: unknown } } | null)?.error;
    // The capability pipeline's limiter names its limit ("Rate limit exceeded (per_minute)."), so
    // that wording becomes words. A rate_limited refusal written for the user (the Coach's cap,
    // AGT-005) keeps its own.
    if (error.status === 429 && body?.code === 'rate_limited' && typeof body.message === 'string' && body.message.startsWith('Rate limit exceeded')) {
        return RATE_LIMITED;
    }

    return typeof body?.message === 'string' && body.message !== '' ? body.message : fallback;
}

const RATE_LIMITED = 'That was a lot in a short time. Wait a moment, then try again.';

/**
 * A failed write's message. A write past its deadline, or with no final answer from the
 * app (a gateway that gave up, the app's retryable 409), may have run (unanswered(),
 * CAP-014), so it says that rather than that the write failed.
 */
export function writeFailureMessage(error: unknown, fallback: string): string {
    if (isTimeout(error)) return 'The server did not answer in time, so this may have worked. Check, then try again.';
    if (error instanceof CapabilityError && error.status !== 0 && unanswered(error)) {
        return 'The server gave no final answer, so this may have worked. Check, then try again.';
    }

    return failureMessage(error, fallback);
}

/** No answer before the deadline (call.ts TIMEOUT_MS): the server may still have done the write. */
export function isTimeout(error: unknown): boolean {
    return error instanceof CapabilityError && error.status === 0 && (error.body as { reason?: unknown } | null)?.reason === 'timeout';
}
