import { onMounted, ref, type Ref } from 'vue';
import { useRouter } from 'vue-router';
import { write, type CapabilityInput } from '@/app/call';
import { writeFailureMessage } from '@/app/errors';
import { findNavigation } from '@/app/navigation';
import { useRetryKey, type RetryKey } from '@/app/retryKey';
import { screenRead, type ReadFailure } from '@/app/screenRead';
import type { ToastResult } from '@/design/Toast.vue';

/**
 * What the screen that opens a Today sheet listens to: `@close` and `@saved`. Listener
 * props, not emits, so the sheet knows when nobody listens: opened at its own route
 * (/today/<date>/adjust, /dev/screens) it goes back down the Today stack instead.
 */
export interface SheetListeners {
    onClose?: () => void;
    /** The write's answer, with the toast and undo token the server wrote (CAP-010). */
    onSaved?: (result: ToastResult) => void;
}

/**
 * A Today sheet's one write: one at a time, the server's words when it refuses (CAP-012),
 * and the same idempotency key for a retry of the same input after no final answer (CAP-014).
 */
export function useSheet(listeners: SheetListeners) {
    const router = useRouter();
    const nav = findNavigation(router);
    const busy = ref(false);
    const error = ref<string | null>(null);
    let last: { name: string; body: string; retry: RetryKey } | null = null;

    function leave(): void {
        if (nav && nav.state.stacks[nav.state.active].length > 1) nav.back();
        else void router.replace('/today');
    }

    function close(): void {
        if (listeners.onClose) listeners.onClose();
        else leave();
    }

    async function save(name: string, input: CapabilityInput, fallback: string): Promise<void> {
        if (busy.value) return;
        busy.value = true;
        error.value = null;
        const body = JSON.stringify(input);
        if (last?.name !== name || last.body !== body) last = { name, body, retry: useRetryKey(name) };
        const retry = last.retry;
        try {
            const result = await write<ToastResult>(name, input, { idempotencyKey: retry.key.value });
            retry.settle();
            if (listeners.onSaved) listeners.onSaved(result);
            else leave();
        } catch (failure) {
            retry.settle(failure);
            error.value = writeFailureMessage(failure, fallback);
        } finally {
            busy.value = false;
        }
    }

    return { busy, error, close, save };
}

/**
 * A sheet's read whose input follows what the user has picked: each load() sends input()
 * as it is then, and an answer to an older pick never replaces a newer one (screenRead).
 * Given a payload (a /dev/screens fixture) it shows that and reads only when asked.
 */
export function useSheetRead<T>(
    name: string,
    input: () => CapabilityInput,
    message: string,
    payload?: T,
): { data: Ref<T | undefined>; failure: Ref<ReadFailure | null>; load: () => Promise<void> } {
    const data = ref(payload) as Ref<T | undefined>;
    const failure = ref<ReadFailure | null>(null);
    const read = screenRead<T>(
        name,
        message,
        (fresh) => {
            failure.value = null;
            data.value = fresh;
        },
        (why) => {
            data.value = undefined;
            failure.value = why;
        },
    );
    const load = () => read(input());

    onMounted(() => {
        if (data.value === undefined) void load();
    });

    return { data, failure, load };
}
