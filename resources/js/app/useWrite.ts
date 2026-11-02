import { ref, type Ref } from 'vue';
import { CapabilityError, write, type CapabilityInput } from './call';
import { isRefusal, isTimeout, writeFailureMessage } from './errors';

/**
 * How a write ended: the server took it, the server refused it (4xx), or no answer came.
 * A 5xx or an answer that could not be read counts as no answer: the write may have happened (OFF-011).
 */
export type WriteOutcome = 'done' | 'refused' | 'no-answer';

/**
 * A screen's writes (CAP-010): one at a time, then the screen's read again. A refusal
 * leaves the server's words in `error` (CAP-012). A write that got no answer may have
 * happened, and the cached reads predate it, so unless the device is offline the screen
 * reads the server's copy, not the cache, to show what the server holds, and `error`
 * says the write may have worked.
 */
export function useWrite(load: (options?: { fresh?: boolean }) => Promise<void>): {
    send: (name: string, input: CapabilityInput, fallback: string) => Promise<WriteOutcome | null>;
    busy: Ref<boolean>;
    error: Ref<string | null>;
} {
    const busy = ref(false);
    const error = ref<string | null>(null);

    /** Resolves with how the write ended, or null when another was still in flight and this one was not sent. */
    async function send(name: string, input: CapabilityInput, fallback: string): Promise<WriteOutcome | null> {
        if (busy.value) return null;
        busy.value = true;
        error.value = null;
        try {
            await write(name, input);
            await load();
            return 'done';
        } catch (failure) {
            error.value = writeFailureMessage(failure, fallback);
            if (!(failure instanceof CapabilityError) || isRefusal(failure)) return 'refused';
            // A failed re-read leaves the screen as load() leaves it; the write's error stays the message.
            if (failure.status !== 0 || isTimeout(failure)) await load({ fresh: true }).catch(() => undefined);
            return 'no-answer';
        } finally {
            busy.value = false;
        }
    }

    return { send, busy, error };
}
