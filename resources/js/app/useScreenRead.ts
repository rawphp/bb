import { onMounted, ref, type Ref } from 'vue';
import { CapabilityError, read, type CapabilityInput } from './call';

export type ScreenFailure = 'signed-out' | 'error';

/**
 * A screen's one read (CAP-004, OFF-003). With a payload (a /dev/screens fixture)
 * it shows that and never reads. Otherwise it reads on mount: a cached copy shows
 * at once, the refresh replaces it, and a refused refresh takes it away. A refresh
 * that lands before read() returns wins over the copy read() was handed.
 */
export function useScreenRead<T>(name: string, input: CapabilityInput, payload?: T) {
    const data = ref(payload) as Ref<T | undefined>;
    const failure = ref<ScreenFailure | null>(null);

    function fail(error: unknown): void {
        data.value = undefined;
        failure.value = error instanceof CapabilityError && error.status === 401 ? 'signed-out' : 'error';
    }

    onMounted(async () => {
        if (data.value !== undefined) return;
        try {
            const cachedOrFresh = await read<T>(name, input, (fresh) => (data.value = fresh), fail);
            if (failure.value === null) data.value ??= cachedOrFresh;
        } catch (error) {
            fail(error);
        }
    });

    return { data, failure };
}
