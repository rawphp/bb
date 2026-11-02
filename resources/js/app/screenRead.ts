import { CapabilityError, read, readFresh, removesCopy, SESSION_GONE, type CapabilityInput } from './call';

// What a screen shows when its read fails: offline with no cached copy (OFF-003),
// signed out, or the screen's own message.
export type ReadFailure =
    | { offline: true; error: null; signedOut: false }
    | { offline: false; error: string; signedOut: boolean };

export function readFailure(error: unknown, message: string): ReadFailure {
    if (error instanceof CapabilityError && error.status === 0) return { offline: true, error: null, signedOut: false };
    // The statuses call.ts clears every cached read for: nothing of the user's may stay on screen.
    if (error instanceof CapabilityError && SESSION_GONE.includes(error.status)) {
        const words = error.status === 401 ? 'You are signed out.' : 'Your session has ended. Sign in again.';
        return { offline: false, error: words, signedOut: true };
    }
    return { offline: false, error: message, signedOut: false };
}

/**
 * A screen's read through call.ts: the cached copy at once, then the refresh or the
 * failure that takes it away (OFF-003). A refresh that lands before read() resolves
 * wins over the copy read() returns, and an answer to an older load (a scope picked
 * since) is ignored, unless it takes away the copy still on screen. `fail` learns
 * whether the data on screen came from this load: if it did, that copy must go
 * (OFF-003); if not, what the screen shows came from another read.
 * A fresh load asks the server, not the cache, for a decision or a write a cached copy
 * may predate. It fails the screen only where a refresh would (401, 419, 403, 404), so
 * offline or past a 5xx the screen keeps what it shows, and it rejects with any error,
 * so the caller knows it did not get the server's answer.
 */
export function screenRead<T>(
    name: string,
    message: string,
    show: (data: T) => void,
    fail: (failure: ReadFailure, hadData: boolean) => void,
): (input?: CapabilityInput, options?: { fresh?: boolean }) => Promise<void> {
    let latest = 0;
    // The load whose data is on screen.
    let shown = 0;

    return async (input = {}, { fresh = false } = {}) => {
        const ticket = ++latest;
        let settled = false;
        const onData = (data: T) => {
            settled = true;
            if (ticket !== latest) return;
            shown = ticket;
            show(data);
        };
        // call.ts reports an error after a shown copy only when that copy is gone
        // (401, 419, 403, 404), so an older load's error matters while its copy is shown.
        const onError = (error: unknown) => {
            settled = true;
            if (ticket === latest || ticket === shown) fail(readFailure(error, message), ticket === shown);
        };

        if (fresh) {
            try {
                onData(await readFresh<T>(name, input));
            } catch (error) {
                if (removesCopy(error)) onError(error);
                throw error;
            }
            return;
        }

        try {
            const data = await read<T>(name, input, onData, onError);
            if (!settled) onData(data);
        } catch (error) {
            onError(error);
        }
    };
}
