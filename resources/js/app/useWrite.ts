import { ref, type Ref } from 'vue';
import { CapabilityError, write, type CapabilityInput } from './call';
import { failureMessage, isTimeout } from './errors';

/** How a write ended: the server took it, the server refused it, or no answer came. */
export type WriteOutcome = 'done' | 'refused' | 'no-answer';

/**
 * A screen's writes (CAP-010): one at a time, then the screen's read again. A failure
 * leaves the server's words in `error` (CAP-012). A write past its deadline may have
 * happened, so the screen reads again to show what the server holds.
 */
export function useWrite(load: () => Promise<void>): {
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
            error.value = failureMessage(failure, fallback);
            if (isTimeout(failure)) await load();
            return failure instanceof CapabilityError && failure.status === 0 ? 'no-answer' : 'refused';
        } finally {
            busy.value = false;
        }
    }

    return { send, busy, error };
}
