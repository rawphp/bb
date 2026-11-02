import { onMounted, onUnmounted, ref, type Ref } from 'vue';
import type { CapabilityInput } from './call';
import { screenRead, type ReadFailure } from './screenRead';

/**
 * A screen's one read (CAP-002, CAP-004), as refs over screenRead(), which owns the
 * cached-copy, refresh and stale-load rules (OFF-003) and what a failure means. Given a
 * payload (a /dev/screens fixture) it shows that and calls nothing; otherwise it reads
 * on mount. load() reads again, for Try again and after a write. Offline with nothing
 * cached it reads again when the device reconnects (OFF-004).
 */
export function useRead<T>(
    name: string,
    input: CapabilityInput,
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
    const load = () => read(input);
    const reconnected = () => {
        if (failure.value?.offline) void load();
    };

    onMounted(() => {
        window.addEventListener('online', reconnected);
        if (data.value === undefined) void load();
    });
    onUnmounted(() => window.removeEventListener('online', reconnected));

    return { data, failure, load };
}
