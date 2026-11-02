<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { clearReads } from '@/app/cache';
import { write } from '@/app/call';
import { failureMessage } from '@/app/errors';
import type { AccountDeleteInput } from '@/contracts/account_delete';
import { TONE_CLASS } from './tones';

// Settings → Delete account (ACC-005). The schema takes only the word DELETE, so the
// button turns on when that is what the field holds; the server refuses anything else.
const router = useRouter();
const typed = ref('');
const busy = ref(false);
const error = ref<string | null>(null);
const confirmation = computed<AccountDeleteInput['confirmation'] | null>(() => (typed.value === 'DELETE' ? 'DELETE' : null));

async function remove(): Promise<void> {
    if (confirmation.value === null || busy.value) return;
    busy.value = true;
    error.value = null;
    try {
        await write('account_delete', { confirmation: confirmation.value } satisfies AccountDeleteInput);
        // The account is gone, so nothing of it may stay on this device.
        await clearReads().catch(() => undefined);
        await router.replace('/');
    } catch (failed) {
        error.value = failureMessage(failed, 'Your account could not be deleted. Try again.');
    } finally {
        busy.value = false;
    }
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
        <p v-if="error" class="rounded-2xl p-4" :class="TONE_CLASS.warning" role="alert">{{ error }}</p>
        <button
            type="button"
            class="min-h-tap w-full rounded-2xl bg-surface py-3 font-medium text-destructive active:opacity-70 disabled:text-secondary-text disabled:opacity-60"
            data-test="delete"
            :disabled="confirmation === null || busy"
            :aria-busy="busy"
            @click="remove"
        >
            Delete my account
        </button>
    </main>
</template>
