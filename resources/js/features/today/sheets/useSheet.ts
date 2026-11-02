import { computed, onMounted, onUnmounted, ref, type Ref } from 'vue';
import { useRouter } from 'vue-router';
import { write, type CapabilityInput } from '@/app/call';
import { writeFailureMessage } from '@/app/errors';
import { findNavigation } from '@/app/navigation';
import { useRetryKey, type RetryKey } from '@/app/retryKey';
import { readFailure, screenRead, SupersededRead, type ReadFailure } from '@/app/screenRead';
import type { ToastResult } from '@/design/Toast.vue';

// The Today sheets' read and write. They stand in for app/useRead and app/useWrite, which
// a sheet cannot use as they are: its read's input follows what the user picks, and its
// save hands the write's answer (the toast) to the screen. Both files change with the
// outbox (#195); fold these into them once it lands.

/**
 * What the screen that opens a Today sheet listens to: `@close` and `@saved`. Listener
 * props, not emits, so the sheet knows when nobody listens: opened at its own route
 * (/today/<date>/adjust, /dev/screens) it goes back down the Today stack instead, and the
 * toast goes with it.
 *
 * A sheet is open while it is mounted. The screen mounts one per opening (v-if, keyed by
 * the day) and unmounts it on @close and @saved, so each opening reads afresh and starts
 * a new entry; a sheet never resets itself.
 */
export interface SheetListeners {
    onClose?: () => void;
    /** The write's answer, with the toast and undo token the server wrote (CAP-010). */
    onSaved?: (result: ToastResult) => void;
}

/**
 * A Today sheet's one write: one at a time, the server's words when it refuses (CAP-012),
 * and the same idempotency key for a retry after no final answer (CAP-014). `locked` is on
 * while a save runs and while one that got no answer may have worked, so the sheet keeps
 * its inputs as sent and the retry is the same write.
 */
export function useSheet(listeners: SheetListeners) {
    const router = useRouter();
    const nav = findNavigation(router);
    const busy = ref(false);
    const error = ref<string | null>(null);
    const unsure = ref(false);
    // A sheet has one write. Its key is renewed by every final answer (RetryKey.settle), and
    // while an attempt got none the sheet is locked, so the retry is the same input.
    let retry: RetryKey | null = null;

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
        const key = (retry ??= useRetryKey(name));
        try {
            const result = await write<ToastResult>(name, input, { idempotencyKey: key.key.value });
            key.settle();
            if (listeners.onSaved) listeners.onSaved(result);
            else leave();
        } catch (failure) {
            key.settle(failure);
            error.value = writeFailureMessage(failure, fallback);
        } finally {
            unsure.value = key.unsure.value;
            busy.value = false;
        }
    }

    return { busy, error, locked: computed(() => busy.value || unsure.value), close, save };
}

/**
 * A sheet's read whose input follows what the user has picked: each load() sends input()
 * as it is then, and an answer to an older pick never replaces a newer one (screenRead).
 * A changed pick loads with `fresh`, from the server: a cached copy of the same pick may
 * predate a move saved since. A failed load keeps what the sheet shows, and `notice` says
 * why beside it, so the user keeps what they typed; only a sheet with nothing to show, or
 * whose copy is gone (signed out), shows the failure in its place. Offline, it loads again
 * when the device reconnects (OFF-004). Given a payload (a /dev/screens fixture) it shows
 * that and reads only when asked.
 */
export function useSheetRead<T>(name: string, input: () => CapabilityInput, message: string, payload?: T) {
    const data = ref(payload) as Ref<T | undefined>;
    const failure = ref<ReadFailure | null>(null);
    // The latest load has not answered yet, so what is on screen may be for an older pick.
    const reading = ref(false);
    let latest = 0;
    let lastFresh = false;

    const read = screenRead<T>(
        name,
        message,
        (fresh) => {
            failure.value = null;
            data.value = fresh;
        },
        (why, hadData) => {
            if (hadData || why.signedOut) data.value = undefined;
            failure.value = why;
        },
    );

    async function load(options: { fresh?: boolean } = {}): Promise<void> {
        const ticket = ++latest;
        lastFresh = options.fresh === true;
        reading.value = true;
        try {
            await read(input(), options);
        } catch (error) {
            if (ticket === latest && !(error instanceof SupersededRead)) failure.value = readFailure(error, message);
        } finally {
            if (ticket === latest) reading.value = false;
        }
    }

    // Shown beside the sheet's content when a load failed but the content stays.
    const notice = computed(() => {
        if (data.value === undefined || failure.value === null) return null;
        return failure.value.offline ? 'No connection. Try again when you are online.' : failure.value.error;
    });

    const reconnected = () => {
        if (failure.value?.offline) void load({ fresh: lastFresh });
    };

    onMounted(() => {
        window.addEventListener('online', reconnected);
        if (data.value === undefined) void load();
    });
    onUnmounted(() => window.removeEventListener('online', reconnected));

    return { data, failure, reading, notice, load };
}
