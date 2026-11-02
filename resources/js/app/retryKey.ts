import { ref, type Ref } from 'vue';
import { CapabilityError } from './call';
import { ulid } from './ulid';

export interface RetryKey {
    /** The Idempotency-Key for the next attempt (CAP-014). */
    readonly key: Ref<string>;
    /** The last attempt got no answer, so it may have run. Its input must not change. */
    readonly unsure: Ref<boolean>;
    /** Call after every attempt with its error, or nothing on success. */
    settle(error?: unknown): void;
    /** Start over with a new key, for a new request. */
    reset(): void;
}

/**
 * One write's idempotency key across retries. The server keeps every answer under its
 * key, refusals included, so a key is reused only while the last attempt got no answer
 * (status 0): the retry then replays whatever that attempt did. Any answer ends the key.
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
        if (error instanceof CapabilityError && error.status === 0) unsure.value = true;
        else reset();
    }

    return { key, unsure, settle, reset };
}
