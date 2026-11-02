<script setup lang="ts">
import { ref } from 'vue';
import { write } from '@/app/call';
import { failureMessage } from '@/app/errors';
import { useRead } from '@/app/useRead';
import type { AgentStatusShowOutput } from '@/contracts/agent_status_show';
import type { CoachConversationClearInput } from '@/contracts/coach_conversation_clear';
import type { CoachEnabledSetInput } from '@/contracts/coach_enabled_set';
import ReadState from './ReadState.vue';
import { TONE_CLASS } from './tones';

// Settings → Coach (AGT-020): this user's Coach on or off, clear the conversation,
// and what the Coach can and cannot do. Settings shows the row only when the
// instance runs the agent (AGT-002), so `enabled` here is whether the Coach is on.
const props = defineProps<{ payload?: AgentStatusShowOutput }>();

const { data: status, failure, load } = useRead<AgentStatusShowOutput>('agent_status_show', props.payload);
const busy = ref(false);
const error = ref<string | null>(null);
const confirming = ref(false);
const cleared = ref<string | null>(null);

async function toggle(event: Event): Promise<void> {
    const box = event.target as HTMLInputElement;
    const on = box.checked;
    busy.value = true;
    error.value = null;
    try {
        await write('coach_enabled_set', { on } satisfies CoachEnabledSetInput);
        await load();
    } catch (failed) {
        error.value = failureMessage(failed, `The Coach could not be turned ${on ? 'on' : 'off'}. Try again.`);
    } finally {
        busy.value = false;
        // Show what the server holds, not what was tapped.
        box.checked = status.value?.enabled ?? false;
    }
}

async function clear(): Promise<void> {
    busy.value = true;
    error.value = null;
    try {
        const result = await write<{ toast?: string | null }>('coach_conversation_clear', {} satisfies CoachConversationClearInput);
        cleared.value = result?.toast ?? 'Conversation cleared.';
        confirming.value = false;
    } catch (failed) {
        error.value = failureMessage(failed, 'The conversation could not be cleared. Try again.');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <main class="bb-screen-content space-y-4">
        <h1 class="text-center text-lg font-semibold">Coach</h1>

        <template v-if="status">
            <label class="flex min-h-tap items-center justify-between gap-3 rounded-2xl bg-surface px-4 py-2">
                <span>Coach</span>
                <input
                    type="checkbox"
                    role="switch"
                    class="peer sr-only"
                    :checked="status.enabled"
                    :disabled="busy"
                    :aria-busy="busy"
                    @change="toggle"
                />
                <span
                    aria-hidden="true"
                    class="relative h-8 w-13 shrink-0 rounded-full bg-tone-neutral-bg transition-colors peer-checked:bg-accent peer-disabled:opacity-60 peer-focus-visible:ring-2 peer-focus-visible:ring-accent after:absolute after:top-0.5 after:left-0.5 after:size-7 after:rounded-full after:bg-white after:shadow after:transition-transform peer-checked:after:translate-x-5"
                />
            </label>
            <p class="px-4 text-sm text-secondary-text">Turning the Coach off hides it everywhere. Your conversation stays until you clear it.</p>

            <p v-if="status.notice" class="rounded-2xl p-4" :class="TONE_CLASS[status.at_cap ? 'warning' : 'info']">{{ status.notice }}</p>
            <p v-if="error" class="rounded-2xl p-4" :class="TONE_CLASS.warning" role="alert">{{ error }}</p>

            <section class="space-y-3 rounded-2xl bg-surface p-4" aria-labelledby="coach-can">
                <h2 id="coach-can" class="font-semibold">What the Coach can do</h2>
                <p>Anything you can do in the app, when you ask: swap an exercise, move a workout, plan a day's meals, explain a deload. It asks before it deletes or ends anything, and every change it makes shows on its screen with Undo.</p>
                <h2 class="font-semibold">What it cannot do</h2>
                <p>Log a set, a meal or a weigh-in you did not tell it about, answer the health screening, delete your account, make tokens or change your subscription. It is not a doctor.</p>
            </section>

            <div class="rounded-2xl bg-surface px-4 py-2">
                <div v-if="confirming" class="space-y-3 py-2">
                    <p>Clear the whole conversation? The changes the Coach made stay.</p>
                    <div class="flex gap-2">
                        <button type="button" class="min-h-tap flex-1 rounded-xl border px-4 active:opacity-70" :disabled="busy" @click="confirming = false">Cancel</button>
                        <button
                            type="button"
                            class="min-h-tap flex-1 rounded-xl border px-4 font-medium text-[light-dark(#d70015,#ff453a)] active:opacity-70"
                            data-test="confirm-clear"
                            :disabled="busy"
                            :aria-busy="busy"
                            @click="clear"
                        >
                            Clear
                        </button>
                    </div>
                </div>
                <button
                    v-else
                    type="button"
                    class="min-h-tap w-full text-left text-[light-dark(#d70015,#ff453a)] active:opacity-70"
                    data-test="clear"
                    @click="(confirming = true), (cleared = null)"
                >
                    Clear conversation
                </button>
                <p v-if="cleared" class="pb-2 text-sm text-secondary-text" role="status">{{ cleared }}</p>
            </div>
        </template>

        <ReadState v-else :failure="failure" what="The Coach settings" @retry="load" />
    </main>
</template>
