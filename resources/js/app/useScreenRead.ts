import { onMounted, ref, type Ref } from 'vue';
import { CapabilityError, read, readFresh, removesCopy, type CapabilityInput } from './call';
import { onScreenReturn } from './screenTop';

export type ReadFailure = 'signed-out' | 'error';

/**
 * A screen's read (CAP-002), with the input it takes (none by default). Given a
 * payload (a /dev/screens fixture) it shows that and calls nothing; otherwise it reads
 * on mount, cached copy first (OFF-003), and again each time the screen comes back on
 * show, so a screen under a pushed one never acts on what it read before.
 * load() reads again, for Try again and after a write. load({ fresh: true }) asks the
 * server, not the cache, for when the cached copy may predate a write that got no answer.
 * When that read fails, the screen keeps what it shows, unless the failure is one after
 * which read() would also stop showing its cached copy (401, 419, 403, 404).
 */
export function useScreenRead<T>(
    name: string,
    payload?: T,
    input: () => CapabilityInput = () => ({}),
): { data: Ref<T | undefined>; failure: Ref<ReadFailure | null>; load: (options?: { fresh?: boolean }) => Promise<void> } {
    const data = ref(payload) as Ref<T | undefined>;
    const failure = ref<ReadFailure | null>(null);
    const fixture = payload !== undefined;

    function fail(error: unknown): void {
        data.value = undefined;
        failure.value = error instanceof CapabilityError && error.status === 401 ? 'signed-out' : 'error';
    }

    // Reads can overlap: a re-read on return to the screen is still refreshing when a
    // write's re-read starts. Only the latest load may change the screen, so an answer
    // fetched before a write never replaces one fetched after it.
    let latest = 0;

    async function load({ fresh = false }: { fresh?: boolean } = {}): Promise<void> {
        const mine = ++latest;
        const current = () => mine === latest;
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
            const copy = fresh ? await readFresh<T>(name, input()) : await read<T>(name, input(), refresh, refused);
            // A refresh that already landed wins over the copy read() was handed.
            if (current() && failure.value === null && !refreshed) data.value = copy;
        } catch (error) {
            if (fresh && data.value !== undefined && !removesCopy(error)) return;
            refused(error);
        }
    }

    onMounted(() => {
        if (!fixture) void load();
    });
    onScreenReturn(() => {
        if (!fixture) void load();
    });

    return { data, failure, load };
}
