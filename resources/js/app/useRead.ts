import { onMounted, onUnmounted, ref, type Ref } from 'vue';
import { CapabilityError, read } from './call';

export type ReadFailure = 'signed-out' | 'offline' | 'error';

/**
 * A screen's one read (CAP-002). Given a payload (a /dev/screens fixture) it shows
 * that and calls nothing; otherwise it reads on mount, cached copy first (OFF-003).
 * load() reads again, for Try again and after a write. Offline with nothing cached it
 * fails as 'offline' and reads again when the device reconnects (OFF-004).
 */
export function useRead<T>(name: string, payload?: T): { data: Ref<T | undefined>; failure: Ref<ReadFailure | null>; load: () => Promise<void> } {
    const data = ref(payload) as Ref<T | undefined>;
    const failure = ref<ReadFailure | null>(null);
    // Each load() is a generation. A refresh or failure from an older one arrives late
    // and would put back data a write has since changed, so it is dropped.
    let generation = 0;

    function fail(error: unknown): void {
        data.value = undefined;
        const status = error instanceof CapabilityError ? error.status : null;
        failure.value = status === 401 ? 'signed-out' : status === 0 ? 'offline' : 'error';
    }

    async function load(): Promise<void> {
        const mine = ++generation;
        const current = () => mine === generation;
        failure.value = null;
        let refreshed = false;
        const refresh = (fresh: T) => {
            if (!current()) return;
            refreshed = true;
            data.value = fresh;
        };
        const refused = (error: unknown) => {
            if (current()) fail(error);
        };
        try {
            const copy = await read<T>(name, {}, refresh, refused);
            // A refresh that already landed wins over the copy read() was handed.
            if (current() && failure.value === null && !refreshed) data.value = copy;
        } catch (error) {
            refused(error);
        }
    }

    const reconnected = () => {
        if (failure.value === 'offline') void load();
    };

    onMounted(() => {
        window.addEventListener('online', reconnected);
        if (data.value === undefined) void load();
    });
    onUnmounted(() => window.removeEventListener('online', reconnected));

    return { data, failure, load };
}
