import { onMounted, onUnmounted, ref, type Ref } from 'vue';
import { cached, readKey } from './cache';
import { WARM_FRESH_MS, type CapabilityInput } from './call';
import { onReplayed } from './outbox';
import { readFailure, screenRead, SupersededRead, type ReadFailure } from './screenRead';
import { onScreenReturn } from './screenTop';

/**
 * A screen's one read (CAP-002, CAP-004), as refs over screenRead(), which owns the
 * cached-copy, refresh and stale-load rules (OFF-003) and what a failure means. Given a
 * payload (a /dev/screens fixture) it shows that and calls nothing; otherwise it reads
 * on mount, and again when the screen comes back on top of its stack, unless the cached
 * copy is no newer than what it shows and younger than a warm keeps (OFF-004). So a
 * screen under a pushed one never acts on what it read before: a write there drops the
 * copy (CAP-010) or that screen's re-read stores a newer one. load() reads again, for
 * Try again and after a write. load({ fresh: true }) reads the server's copy (see
 * screenRead) and rejects when it did not get it. Offline with nothing cached it reads
 * again when the device reconnects (OFF-004), and it reads again when a replayed outbox
 * entry's answer invalidates it (CAP-010), so the pending numbers give way to the server's.
 */
export function useRead<T>(
    name: string,
    input: CapabilityInput,
    message: string,
    payload?: T,
): { data: Ref<T | undefined>; failure: Ref<ReadFailure | null>; load: (options?: { fresh?: boolean }) => Promise<void> } {
    const data = ref(payload) as Ref<T | undefined>;
    const failure = ref<ReadFailure | null>(null);
    // When this screen last showed an answer. A read stores its answer before it is shown,
    // so a cached copy fetched after this came from another screen's read.
    let shownAt = 0;
    const read = screenRead<T>(
        name,
        message,
        (fresh) => {
            failure.value = null;
            data.value = fresh;
            shownAt = Date.now();
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

    const key = readKey(name, input);
    let stopListening = () => {};

    onMounted(() => {
        window.addEventListener('online', reconnected);
        if (!fixture) {
            stopListening = onReplayed((keys) => {
                if (keys.includes(key)) void load().catch(() => undefined);
            });
        }
        if (data.value === undefined) void load();
    });
    onScreenReturn(async () => {
        if (fixture) return;
        const hit = await cached(key).catch(() => undefined);
        if (hit !== undefined && hit.fetchedAt <= shownAt && Date.now() - hit.fetchedAt < WARM_FRESH_MS) return;
        void load();
    });
    onUnmounted(() => {
        window.removeEventListener('online', reconnected);
        stopListening();
    });

    return { data, failure, load };
}
