import { ref } from 'vue';
import { CapabilityError, write } from '@/app/call';
import { writeFailureMessage } from '@/app/errors';
import { useRetryKey, type RetryKey } from '@/app/retryKey';
import type { TrainingWeekdaysChangeInput } from '@/contracts/training_weekdays_change';

export type FewerDays = NonNullable<TrainingWeekdaysChangeInput['fewer_days']>;

const NAME = 'training_weekdays_change';

/** The server refused the change to ask what the mesocycle does with fewer days (SCH-039). */
function asksFewerDays(failure: unknown): boolean {
    if (!(failure instanceof CapabilityError) || failure.status !== 422) return false;
    const violations = (failure.body as { error?: { violations?: { field?: unknown }[] } } | null)?.error?.violations;

    return Array.isArray(violations) && violations.some((violation) => violation.field === 'fewer_days');
}

/**
 * SCH-039: a change of training weekdays while a mesocycle runs, for Edit schedule and
 * Settings (rp-clone's weekdayChangeSheet modifier). change() sends the new weekdays.
 * When the server answers that the mesocycle must change shape, `asking` holds them and
 * the screen shows WeekdayChangeSheet; choose() sends them again with the answer, and
 * cancel() drops the question, so nothing is saved. Like useWrite, a retry of the same
 * change after no final answer reuses its idempotency key (CAP-014). It reads nothing
 * again: the screen saves the rest of its change, then reads.
 */
export function useWeekdayChange() {
    const asking = ref<number[] | null>(null);
    const busy = ref(false);
    const error = ref<string | null>(null);
    let last: { body: string; retry: RetryKey } | null = null;

    /** True once the server took the change. */
    async function change(weekdays: number[], fewerDays: FewerDays | null = null): Promise<boolean> {
        if (busy.value) return false;
        const input = { weekdays, fewer_days: fewerDays } satisfies TrainingWeekdaysChangeInput;
        const body = JSON.stringify(input);
        if (last?.body !== body) last = { body, retry: useRetryKey(NAME) };
        const { retry } = last;
        busy.value = true;
        error.value = null;
        try {
            await write(NAME, input, { idempotencyKey: retry.key.value });
            retry.settle();
            asking.value = null;
            return true;
        } catch (failure) {
            retry.settle(failure);
            if (fewerDays === null && asksFewerDays(failure)) asking.value = weekdays;
            else error.value = writeFailureMessage(failure, 'Your training days could not be changed.');
            return false;
        } finally {
            busy.value = false;
        }
    }

    function choose(fewerDays: FewerDays): Promise<boolean> {
        return asking.value ? change(asking.value, fewerDays) : Promise.resolve(false);
    }

    function cancel(): void {
        asking.value = null;
        error.value = null;
    }

    return { asking, busy, error, change, choose, cancel };
}
