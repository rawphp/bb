<script setup lang="ts">
import { computed, ref } from 'vue';
import { writeNow } from '@/app/call';
import { writeFailureMessage } from '@/app/errors';
import type { CoachChangesListOutput } from '@/contracts/coach_changes_list';
import type { UndoApplyInput } from '@/contracts/undo_apply';

// One Coach write, as a compact card under the reply (AGT-013): what changed in the
// server's words, a link to the screen it shows on, and Undo while the server still
// offers it (CAP-013). Undo runs as the Toast's does, and a refusal shows the server's message.
const props = defineProps<{ change: CoachChangesListOutput['changes'][number] }>();
const emit = defineEmits<{ open: [] }>();

const undone = ref(false);
const undoing = ref(false);
const failure = ref<string | null>(null);
const token = computed(() => (undone.value ? null : (props.change.undo ?? null)));

async function undo(): Promise<void> {
    const sent = token.value;
    if (sent === null || undoing.value) return;
    undoing.value = true;
    failure.value = null;
    try {
        await writeNow('undo_apply', { token: sent } satisfies UndoApplyInput);
        undone.value = true;
    } catch (error) {
        failure.value = writeFailureMessage(error, "Couldn't undo. Try again.");
    } finally {
        undoing.value = false;
    }
}
</script>

<template>
    <div class="rounded-xl border border-secondary-text/25 bg-background px-3 py-2" data-test="change-card">
        <div class="flex items-center gap-2">
            <svg viewBox="0 0 24 24" class="size-4 shrink-0 text-tone-good" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" aria-hidden="true">
                <path d="m5 12 5 5 9-10" />
            </svg>
            <span class="min-w-0 flex-1 text-sm text-primary-text">{{ change.title }}</span>
            <span v-if="undone" class="text-xs text-secondary-text">Undone</span>
            <button
                v-if="token !== null"
                type="button"
                data-test="undo"
                :disabled="undoing"
                aria-description="Reverts this change"
                class="min-h-tap min-w-tap shrink-0 text-sm font-semibold text-accent disabled:opacity-50"
                @click="undo"
            >
                Undo
            </button>
        </div>
        <RouterLink v-if="change.screen" :to="change.screen" class="inline-flex min-h-tap items-center text-sm text-accent" data-test="change-screen" @click="emit('open')">
            Show me
        </RouterLink>
        <p v-if="failure" role="alert" class="pb-1 text-sm text-tone-warning" data-test="undo-error">{{ failure }}</p>
    </div>
</template>
