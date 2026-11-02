import { CapabilityError } from '@/app/call';

// What a screen shows when its read fails: offline with no cached copy (OFF-003),
// signed out, or the screen's own message.
export type ReadFailure = { offline: true; error: null } | { offline: false; error: string };

export function readFailure(error: unknown, message: string): ReadFailure {
    if (error instanceof CapabilityError && error.status === 0) return { offline: true, error: null };
    if (error instanceof CapabilityError && error.status === 401) return { offline: false, error: 'You are signed out.' };
    return { offline: false, error: message };
}
