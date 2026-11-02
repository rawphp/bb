import { onMounted, ref, type Ref } from 'vue';
import { CapabilityError, read, type CapabilityInput } from './call';
import { onScreenReturn } from './screenTop';

export type ReadFailure = 'signed-out' | 'error';

/**
 * A screen's read (CAP-002), with the input it takes (none by default). Given a
 * payload (a /dev/screens fixture) it shows that and calls nothing; otherwise it reads
 * on mount, cached copy first (OFF-003), and again each time the screen comes back on
 * show, so a screen under a pushed one never acts on what it read before.
 * load() reads again, for Try again and after a write.
 */
export function useScreenRead<T>(
    name: string,
    payload?: T,
    input: () => CapabilityInput = () => ({}),
): { data: Ref<T | undefined>; failure: Ref<ReadFailure | null>; load: () => Promise<void> } {
    const data = ref(payload) as Ref<T | undefined>;
    const failure = ref<ReadFailure | null>(null);
    const fixture = payload !== undefined;

    function fail(error: unknown): void {
        data.value = undefined;
        failure.value = error instanceof CapabilityError && error.status === 401 ? 'signed-out' : 'error';
    }

    async function load(): Promise<void> {
        failure.value = null;
        let refreshed = false;
        const refresh = (fresh: T) => {
            refreshed = true;
            data.value = fresh;
        };
        try {
            const copy = await read<T>(name, input(), refresh, fail);
            // A refresh that already landed wins over the copy read() was handed.
            if (failure.value === null && !refreshed) data.value = copy;
        } catch (error) {
            fail(error);
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
