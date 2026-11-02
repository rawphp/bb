import { CapabilityError, read, SESSION_GONE, type CapabilityInput } from './call';

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
 * since) is ignored. `fail` learns whether this load had shown data: if it had, that
 * copy must go (OFF-003); if not, what the screen shows came from another read.
 */
export function screenRead<T>(
    name: string,
    message: string,
    show: (data: T) => void,
    fail: (failure: ReadFailure, hadData: boolean) => void,
): (input?: CapabilityInput) => Promise<void> {
    let latest = 0;

    return async (input = {}) => {
        const ticket = ++latest;
        let settled = false;
        let hadData = false;
        const onData = (data: T) => {
            settled = true;
            if (ticket !== latest) return;
            hadData = true;
            show(data);
        };
        const onError = (error: unknown) => {
            settled = true;
            if (ticket === latest) fail(readFailure(error, message), hadData);
        };

        try {
            const data = await read<T>(name, input, onData, onError);
            if (!settled) onData(data);
        } catch (error) {
            onError(error);
        }
    };
}
