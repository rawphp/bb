import { ref } from 'vue';
import { CapabilityError, writeNow } from '@/app/call';
import { writeFailureMessage } from '@/app/errors';
import { useRetryKey, type RetryKey } from '@/app/retryKey';
import type { TrainingWeekdaysChangeInput } from '@/contracts/training_weekdays_change';

export type FewerDays = NonNullable<TrainingWeekdaysChangeInput['fewer_days']>;

const NAME = 'training_weekdays_change';

/**
 * The server refused the change to ask what the mesocycle does with fewer days (SCH-039):
 * its words on what each choice does, or null when it is not asking.
 */
function fewerDaysQuestion(failure: unknown): string | null {
    if (!(failure instanceof CapabilityError) || failure.status !== 422) return null;
    const error = (failure.body as { error?: { message?: unknown; violations?: { field?: unknown }[] } } | null)?.error;
    const asks = Array.isArray(error?.violations) && error.violations.some((violation) => violation.field === 'fewer_days');

    return asks ? (typeof error?.message === 'string' ? error.message : '') : null;
}

/**
 * SCH-039: a change of training weekdays while a mesocycle runs, for Edit schedule and
 * Settings (rp-clone's weekdayChangeSheet modifier). change() sends the new weekdays.
 * When the server answers that the mesocycle must change shape, `asking` holds them,
 * `question` the server's words on it, and the screen shows WeekdayChangeSheet; choose() sends them again with the answer, and
 * cancel() drops the question, so nothing is saved. Like useWrite, a retry of the same
 * change after no final answer reuses its idempotency key (CAP-014). It reads nothing
 * again: the screen saves the rest of its change, then reads.
 */
export function useWeekdayChange() {
    const asking = ref<number[] | null>(null);
    const question = ref<string | null>(null);
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
            await writeNow(NAME, input, { idempotencyKey: retry.key.value });
            retry.settle();
            asking.value = null;
            return true;
        } catch (failure) {
            retry.settle(failure);
            const asked = fewerDays === null ? fewerDaysQuestion(failure) : null;
            if (asked !== null) {
                asking.value = weekdays;
                question.value = asked;
            } else error.value = writeFailureMessage(failure, 'Your training days could not be changed.');
            return false;
        } finally {
            busy.value = false;
        }
    }

    function choose(fewerDays: FewerDays): Promise<boolean> {
        return asking.value ? change(asking.value, fewerDays) : Promise.resolve(false);
    }

    /** Drops the question and any error, so nothing of this change shows. */
    function cancel(): void {
        asking.value = null;
        question.value = null;
        error.value = null;
    }

    return { asking, question, busy, error, change, choose, cancel };
}
