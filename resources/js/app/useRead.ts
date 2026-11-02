import { onMounted, onUnmounted, ref, type Ref } from 'vue';
import { cached, readKey } from './cache';
import { WARM_FRESH_MS, type CapabilityInput } from './call';
import { readFailure, screenRead, SupersededRead, type ReadFailure } from './screenRead';
import { onScreenReturn } from './screenTop';

/**
 * A screen's one read (CAP-002, CAP-004), as refs over screenRead(), which owns the
 * cached-copy, refresh and stale-load rules (OFF-003) and what a failure means. Given a
 * payload (a /dev/screens fixture) it shows that and calls nothing; otherwise it reads
 * on mount, and again when the screen comes back on top of its stack, if a write dropped
 * its cached copy (CAP-010) or the copy is older than a warm keeps (OFF-004), so a screen
 * under a pushed one never acts on what it read before. load() reads again, for
 * Try again and after a write. load({ fresh: true }) reads the server's copy (see
 * screenRead) and rejects when it did not get it. Offline with nothing cached it reads
 * again when the device reconnects (OFF-004).
 */
export function useRead<T>(
    name: string,
    input: CapabilityInput,
    message: string,
    payload?: T,
): { data: Ref<T | undefined>; failure: Ref<ReadFailure | null>; load: (options?: { fresh?: boolean }) => Promise<void> } {
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
    const fixture = payload !== undefined;
    const load = async (options: { fresh?: boolean } = {}): Promise<void> => {
        try {
            await read(input, options);
        } catch (error) {
            // A fresh read with nothing on screen to keep says why, as a plain one would. One a
            // newer load overtook leaves the screen to that load.
            if (data.value === undefined && !(error instanceof SupersededRead)) failure.value = readFailure(error, message);
            throw error;
        }
    };
    const reconnected = () => {
        if (failure.value?.offline) void load();
    };

    onMounted(() => {
        window.addEventListener('online', reconnected);
        if (data.value === undefined) void load();
    });
    onScreenReturn(async () => {
        if (fixture) return;
        const hit = await cached(readKey(name, input)).catch(() => undefined);
        if (hit !== undefined && Date.now() - hit.fetchedAt < WARM_FRESH_MS) return;
        void load();
    });
    onUnmounted(() => window.removeEventListener('online', reconnected));

    return { data, failure, load };
}
