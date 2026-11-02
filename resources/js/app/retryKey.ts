import { ref, type Ref } from 'vue';
import { CapabilityError } from './call';
import { ulid } from './ulid';

export interface RetryKey {
    /** The Idempotency-Key for the next attempt (CAP-014). */
    readonly key: Ref<string>;
    /** The last attempt got no final answer, so it may have run. Its input must not change. */
    readonly unsure: Ref<boolean>;
    /** Call after every attempt with its error, or nothing on success. */
    settle(error?: unknown): void;
    /** Start over with a new key, for a new request. */
    reset(): void;
}

/**
 * One write's idempotency key across retries. The server keeps every answer under its
 * key, refusals included, so a key is reused only while the last attempt got no final
 * answer (unanswered()): the retry then replays whatever that attempt did. Any answer
 * from the app ends the key.
 */
export function useRetryKey(name: string): RetryKey {
    const fresh = () => `${name}:${ulid()}`;
    const key = ref(fresh());
    const unsure = ref(false);

    function reset(): void {
        key.value = fresh();
        unsure.value = false;
    }

    function settle(error?: unknown): void {
        if (unanswered(error)) unsure.value = true;
        else reset();
    }

    return { key, unsure, settle, reset };
}

/**
 * Whether an attempt ended without the app's final answer, so it may have run or may
 * still be running: no response at all (status 0); a 5xx without the app's error
 * envelope, which a proxy sends when it gives up while the app carries on; or the app's
 * own "already processing" 409, which it marks retryable (CAP-014, D-005).
 */
export function unanswered(error: unknown): boolean {
    if (!(error instanceof CapabilityError)) return false;
    if (error.status === 0) return true;
    const envelope = (error.body as { error?: unknown } | null)?.error;
    const app = typeof envelope === 'object' && envelope !== null ? (envelope as { retryable?: unknown }) : null;
    if (error.status >= 500) return app === null;

    return error.status === 409 && app?.retryable === true;
}
