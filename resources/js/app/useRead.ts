import { onMounted, ref, type Ref } from 'vue';
import { CapabilityError, read } from './call';

export type ReadFailure = 'signed-out' | 'error';

/**
 * A screen's one read (CAP-002). Given a payload (a /dev/screens fixture) it shows
 * that and calls nothing; otherwise it reads on mount, cached copy first (OFF-003).
 * load() reads again, for Try again and after a write.
 */
export function useRead<T>(name: string, payload?: T): { data: Ref<T | undefined>; failure: Ref<ReadFailure | null>; load: () => Promise<void> } {
    const data = ref(payload) as Ref<T | undefined>;
    const failure = ref<ReadFailure | null>(null);

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
            const copy = await read<T>(name, {}, refresh, fail);
            // A refresh that already landed wins over the copy read() was handed.
            if (failure.value === null && !refreshed) data.value = copy;
        } catch (error) {
            fail(error);
        }
    }

    onMounted(() => {
        if (data.value === undefined) void load();
    });

    return { data, failure, load };
}
