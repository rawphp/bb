<script setup lang="ts">
import { computed, ref } from 'vue';
import { writeNow } from '@/app/call';
import { failureMessage } from '@/app/errors';
import { useRead } from '@/app/useRead';
import type { AgentStatusShowOutput } from '@/contracts/agent_status_show';
import type { CoachConversationClearInput } from '@/contracts/coach_conversation_clear';
import type { CoachEnabledSetInput } from '@/contracts/coach_enabled_set';
import BottomSheet from '@/design/BottomSheet.vue';
import Toast, { type ToastResult } from '@/design/Toast.vue';
import ReadState from '@/app/ReadState.vue';
import { toneClasses } from '@/design/tones';

// Settings → Coach (AGT-020): this user's Coach on or off, clear the conversation,
// and what the Coach can and cannot do. The only way here is Settings' Coach row,
// which settings_show leaves out when the instance does not run the agent (AGT-002);
// the route links from no tab placeholder for that reason.
//
// `enabled` is the instance's flag, not the user's. The user's own setting is `on`,
// which #49 left for the API issue that registers coach_enabled_set to add to
// agent_status_show. Until a read carries it, the screen shows no switch rather than
// pass the instance flag off as the user's choice.
type CoachStatus = AgentStatusShowOutput & { on?: boolean | null };

const props = defineProps<{ payload?: CoachStatus }>();

const { data: status, failure, load } = useRead<CoachStatus>('agent_status_show', {}, 'The Coach settings could not be loaded.', props.payload);
const on = computed(() => (typeof status.value?.on === 'boolean' ? status.value.on : null));
const busy = ref(false);
const error = ref<string | null>(null);
const confirming = ref(false);
const clearError = ref<string | null>(null);
const cleared = ref<ToastResult>({});

async function toggle(event: Event): Promise<void> {
    const box = event.target as HTMLInputElement;
    const wanted = box.checked;
    busy.value = true;
    error.value = null;
    try {
        await writeNow('coach_enabled_set', { on: wanted } satisfies CoachEnabledSetInput);
        await load();
    } catch (failed) {
        error.value = failureMessage(failed, `The Coach could not be turned ${wanted ? 'on' : 'off'}. Try again.`);
    } finally {
        busy.value = false;
        // Show what the server holds, not what was tapped.
        box.checked = on.value ?? false;
    }
}

async function clear(): Promise<void> {
    busy.value = true;
    clearError.value = null;
    try {
        const result = await writeNow<ToastResult | undefined>('coach_conversation_clear', {} satisfies CoachConversationClearInput);
        cleared.value = { toast: result?.toast || 'Conversation cleared.', undo: result?.undo ?? null };
        confirming.value = false;
    } catch (failed) {
        clearError.value = failureMessage(failed, 'The conversation could not be cleared. Try again.');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <main class="bb-screen-content space-y-4">
        <h1 class="text-center text-lg font-semibold">Coach</h1>

        <template v-if="status">
            <label v-if="on !== null" class="flex min-h-tap items-center justify-between gap-3 rounded-2xl bg-surface px-4 py-2">
                <span>Coach</span>
                <input
                    type="checkbox"
                    role="switch"
                    class="peer sr-only"
                    :checked="on"
                    :disabled="busy"
                    :aria-busy="busy"
                    @change="toggle"
                />
                <!-- The knob is white in both appearances, as an iOS switch's is; a surface-coloured knob would vanish on the dark track. -->
                <span
                    aria-hidden="true"
                    class="relative h-8 w-13 shrink-0 rounded-full bg-tone-neutral-bg transition-colors peer-checked:bg-accent peer-disabled:opacity-60 peer-focus-visible:ring-2 peer-focus-visible:ring-accent after:absolute after:top-0.5 after:left-0.5 after:size-7 after:rounded-full after:bg-white after:shadow after:transition-transform peer-checked:after:translate-x-5"
                />
            </label>
            <p v-if="on !== null" class="px-4 text-sm text-secondary-text">Turning the Coach off hides it everywhere. Your conversation stays until you clear it.</p>
            <p v-else class="rounded-2xl bg-surface p-4 text-sm text-secondary-text" data-test="no-switch">
                Turning the Coach off for your account isn't available on this server yet.
            </p>

            <p v-if="status.notice_status" class="rounded-2xl p-4" :class="toneClasses[status.notice_status.tone]" data-test="notice">{{ status.notice_status.text }}</p>
            <p v-if="error" class="rounded-2xl p-4" :class="toneClasses.warning" role="alert">{{ error }}</p>

            <section class="space-y-3 rounded-2xl bg-surface p-4" aria-labelledby="coach-can">
                <h2 id="coach-can" class="font-semibold">What the Coach can do</h2>
                <p>Anything you can do in the app, when you ask: swap an exercise, move a workout, plan a day's meals, explain a deload. It asks before it deletes or ends anything, and every change it makes shows on its screen with Undo.</p>
                <h2 class="font-semibold">What it cannot do</h2>
                <p>Log a set, a meal or a weigh-in you did not tell it about, delete your account, make or revoke tokens or change your subscription. It is not a doctor.</p>
            </section>

            <div class="rounded-2xl bg-surface px-4 py-2">
                <button
                    type="button"
                    class="min-h-tap w-full text-left text-tone-warning active:opacity-70"
                    data-test="clear"
                    @click="(confirming = true), (cleared = {}), (clearError = null)"
                >
                    Clear conversation
                </button>
            </div>
            <BottomSheet
                :open="confirming"
                title="Clear conversation"
                save-title="Clear"
                :heights="['half']"
                :save-disabled="busy"
                :cancel-disabled="busy"
                :dismissible="!busy"
                :error="clearError"
                @cancel="confirming = false"
                @save="clear"
            >
                <p class="p-4">Clear the whole conversation? The changes the Coach made stay.</p>
            </BottomSheet>
            <Toast :result="cleared" />
        </template>

        <ReadState v-else :failure="failure" what="The Coach settings" @retry="load" />
    </main>
</template>
