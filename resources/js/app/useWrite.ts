import { ref, type Ref } from 'vue';
import { CapabilityError, write, type CapabilityInput } from './call';
import { isTimeout, writeFailureMessage } from './errors';
import { unanswered, useRetryKey, type RetryKey } from './retryKey';

/**
 * How a write ended: the server took it, the app answered with a refusal, or no final
 * answer came (unanswered(): no response, a gateway's 5xx, the app's retryable 409), so
 * the write may have happened.
 */
export type WriteOutcome = 'done' | 'refused' | 'no-answer';

/**
 * A screen's writes (CAP-010): one at a time, then the screen's read again. Each carries
 * an idempotency key (CAP-014), and a retry of the same write with the same input after
 * no final answer reuses it, so the server replays that attempt instead of running it
 * twice. A refusal leaves the server's words in `error` (CAP-012). A write that got no
 * final answer may have happened, and the cached reads predate it, so unless the device
 * is offline the screen reads the server's copy, not the cache, and `error` says the
 * write may have worked.
 */
export function useWrite(load: (options?: { fresh?: boolean }) => Promise<void>): {
    send: (name: string, input: CapabilityInput, fallback: string) => Promise<WriteOutcome | null>;
    busy: Ref<boolean>;
    error: Ref<string | null>;
} {
    const busy = ref(false);
    const error = ref<string | null>(null);
    // The last write sent. Its key outlives an attempt only while that attempt got no
    // final answer (RetryKey.settle), so a retry of the same write reuses it.
    let last: { name: string; body: string; retry: RetryKey } | null = null;

    function keyFor(name: string, input: CapabilityInput): RetryKey {
        const body = JSON.stringify(input);
        if (last?.name !== name || last.body !== body) last = { name, body, retry: useRetryKey(name) };

        return last.retry;
    }

    /** Resolves with how the write ended, or null when another was still in flight and this one was not sent. */
    async function send(name: string, input: CapabilityInput, fallback: string): Promise<WriteOutcome | null> {
        if (busy.value) return null;
        busy.value = true;
        error.value = null;
        const retry = keyFor(name, input);
        try {
            await write(name, input, { idempotencyKey: retry.key.value });
            retry.settle();
            await load();
            return 'done';
        } catch (failure) {
            retry.settle(failure);
            error.value = writeFailureMessage(failure, fallback);
            if (!unanswered(failure)) return 'refused';
            // A failed re-read leaves the screen as load() leaves it; the write's error stays the message.
            if ((failure as CapabilityError).status !== 0 || isTimeout(failure)) await load({ fresh: true }).catch(() => undefined);
            return 'no-answer';
        } finally {
            busy.value = false;
        }
    }

    return { send, busy, error };
}
