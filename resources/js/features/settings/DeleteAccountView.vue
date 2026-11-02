<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { clearReads } from '@/app/cache';
import { CapabilityError, SESSION_GONE, writeNow } from '@/app/call';
import { failureMessage } from '@/app/errors';
import { entries, removeEntries } from '@/app/outbox';
import { useRetryKey } from '@/app/retryKey';
import type { AccountDeleteInput } from '@/contracts/account_delete';
import { toneClasses } from '@/design/tones';

// Settings → Delete account (ACC-005). The schema takes only the word DELETE, so the
// button turns on when that is what the field holds; the server refuses anything else.
const router = useRouter();
const typed = ref('');
const busy = ref(false);
const error = ref<string | null>(null);
// A retry after a lost answer is the same delete (CAP-014).
const retry = useRetryKey('account_delete');
const confirmation = computed<AccountDeleteInput['confirmation'] | null>(() => (typed.value === 'DELETE' ? 'DELETE' : null));

async function remove(): Promise<void> {
    if (confirmation.value === null || busy.value) return;
    busy.value = true;
    error.value = null;
    try {
        await writeNow('account_delete', { confirmation: confirmation.value } satisfies AccountDeleteInput, { idempotencyKey: retry.key.value });
        retry.settle();
        await leave();
    } catch (failed) {
        // Read before settle(): whether an earlier try went unanswered decides what a 401 means.
        const afterLostAnswer = retry.unsure.value;
        retry.settle(failed);
        await failedDelete(failed, afterLostAnswer);
    } finally {
        busy.value = false;
    }
}

async function failedDelete(failed: unknown, afterLostAnswer: boolean): Promise<void> {
    const status = failed instanceof CapabilityError ? failed.status : null;
    if (status !== null && SESSION_GONE.includes(status)) {
        // After a try that got no answer, the session may be gone because that delete ran,
        // or that try never reached the server and the session simply ended: no telling which.
        if (afterLostAnswer) {
            await clearReads().catch(() => undefined);
            error.value = "Your session has ended, so we can't tell whether your account was deleted. Sign in again to check: if you can't, it was.";
            return;
        }
        // On a first try it only means this session ended: nothing was deleted.
        error.value = 'You were signed out, so nothing was deleted. Sign in again, then delete your account.';
        return;
    }
    // No final answer (offline, a gateway that gave up, or the first try still running): the
    // delete may have run. The cached reads go either way; they come back if it didn't.
    if (retry.unsure.value) {
        await clearReads().catch(() => undefined);
        error.value =
            status === 0
                ? "No connection, so we can't tell whether your account was deleted. Try again when you are online."
                : failureMessage(failed, "We can't tell whether your account was deleted. Try again in a moment.");
        return;
    }
    error.value = failureMessage(failed, 'Your account could not be deleted. Try again.');
}

// The account is gone, so nothing of it may stay on this device: no cached read and no outbox entry. If the clear fails it
// still leaves: the session is gone too, so the next read answers 401 and call.ts clears
// every cached read then (forget()).
async function leave(): Promise<void> {
    await clearReads().catch(() => undefined);
    // What is left in the outbox (changes that couldn't sync) has no account to go to. It
    // leaves the screen at once; the stored copies follow.
    void removeEntries(entries.value.map((entry) => entry.seq));
    await router.replace('/');
}
</script>

<template>
    <main class="bb-screen-content space-y-6">
        <h1 class="text-center text-lg font-semibold">Delete account</h1>
        <p class="rounded-2xl bg-surface p-4">
            This permanently deletes your account and everything in it: your profile, plan, weigh-ins, workouts and meals. It cannot be undone.
        </p>
        <label class="block">
            <span class="sr-only">Confirmation</span>
            <input
                v-model="typed"
                type="text"
                class="min-h-tap w-full rounded-2xl bg-surface px-4"
                placeholder="Type DELETE"
                autocapitalize="characters"
                autocomplete="off"
                autocorrect="off"
                spellcheck="false"
                aria-describedby="delete-hint"
                :disabled="busy"
            />
            <span id="delete-hint" class="mt-2 block px-4 text-sm text-secondary-text">Type DELETE in capital letters to turn the button on.</span>
        </label>
        <p v-if="error" class="rounded-2xl p-4" :class="toneClasses.warning" role="alert">{{ error }}</p>
        <button
            type="button"
            class="min-h-tap w-full rounded-2xl bg-surface py-3 font-medium text-tone-warning active:opacity-70 disabled:text-secondary-text disabled:opacity-60"
            data-test="delete"
            :disabled="confirmation === null || busy"
            :aria-busy="busy"
            @click="remove"
        >
            Delete my account
        </button>
    </main>
</template>
