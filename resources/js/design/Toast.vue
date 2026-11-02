<script lang="ts">
/**
 * The two fields of a write's answer the toast shows, as the server sends them
 * (CAP-010), so a host passes any write's output straight in: `<Toast :result="output">`.
 *
 * Placement belongs to whoever shows the toast, not to the toast: the shell's toast host
 * (19-views.md) draws it in TOAST_HOST, the live region that announces it, and it alone knows
 * which screen is on top. A Toast drawn anywhere else passes `inline` and carries its own
 * status region. In development a Toast that is neither warns.
 */
export interface ToastResult {
    /** The toast the server wrote. Null or empty draws nothing. */
    toast?: string | null;
    /** The write's undo token (CAP-013). Without one there is no Undo. */
    undo?: string | null;
}
</script>

<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import { CapabilityError, write } from '@/app/call';
import type { UndoApplyInput } from '@/contracts/undo_apply';

// A message with Undo, for every automatic change (UI-010), ported from rp-clone's Toast.swift.
// VIEW-004 states take the same props as ItemCard and ProgressBar.
const props = withDefaults(
    defineProps<{
        result: ToastResult;
        loading?: boolean;
        error?: string | null;
        /** The change is waiting in the outbox (OFF-008). */
        pending?: boolean;
        disabled?: boolean;
        /** Drawn in place rather than in TOAST_HOST (the gallery), so it carries its own live region. */
        inline?: boolean;
    }>(),
    { loading: false, error: null, pending: false, disabled: false, inline: false },
);

// Each answer names the token it was for, so the host still hears an undo that lands
// after it has shown the next toast, and can refresh or report it.
const emit = defineEmits<{ undone: [result: unknown, token: string]; 'undo-failed': [message: string, token: string] }>();

const uid = useId();
// The token whose undo_apply is running. Only the toast it belongs to is busy.
const undoing = ref<string | null>(null);
const failure = ref<string | null>(null);

const rootEl = ref<HTMLElement | null>(null);

// Outside a live region a toast is never announced (UI-030); say so while building, not in production.
watch(rootEl, (el) => {
    if (import.meta.env.DEV && el && !props.inline && !el.parentElement?.closest('[aria-live], [role="status"]')) {
        console.warn('Toast is outside a live region: draw it in TOAST_HOST, or pass inline.');
    }
});

const message = computed(() => props.result.toast ?? '');
const token = computed(() => props.result.undo ?? null);
const busy = computed(() => props.loading || (undoing.value !== null && undoing.value === token.value));
const shownError = computed(() => failure.value ?? props.error);

// A refusal belongs to the change it was about, not to the next toast.
watch(token, () => (failure.value = null));

// The server writes the refusal message (CAP-012); with no answer at all, say so plainly.
function messageOf(error: unknown): string {
    const body = error instanceof CapabilityError ? (error.body as { error?: { message?: unknown } } | null) : null;
    const text = body?.error?.message;

    return typeof text === 'string' && text !== '' ? text : "Couldn't undo. Try again.";
}

async function undo(): Promise<void> {
    const sent = token.value;
    if (sent === null || busy.value || props.disabled) {
        return;
    }
    undoing.value = sent;
    failure.value = null;
    try {
        emit('undone', await write('undo_apply', { token: sent } satisfies UndoApplyInput), sent);
    } catch (error) {
        const text = messageOf(error);
        emit('undo-failed', text, sent);
        // The host may have shown another toast meanwhile; the refusal is not shown on it.
        if (token.value === sent) failure.value = text;
    } finally {
        if (undoing.value === sent) undoing.value = null;
    }
}
</script>

<template>
    <!-- Inline, this status region stays in the page so a toast that appears in it is announced. -->
    <div
        v-if="message || inline"
        ref="rootEl"
        :role="inline ? 'status' : undefined"
        :aria-busy="message && busy ? 'true' : undefined"
        data-toast
    >
        <template v-if="message">
            <div class="flex items-center gap-3 rounded-xl border border-secondary-text/25 bg-surface pr-2 pl-4">
                <span class="flex min-w-0 grow flex-col gap-0.5 py-2">
                    <span class="text-sm text-primary-text">{{ message }}</span>
                    <span v-if="pending" :id="`${uid}-pending`" class="text-xs text-secondary-text">Waiting to sync</span>
                </span>
                <button
                    v-if="token !== null"
                    type="button"
                    :aria-describedby="pending ? `${uid}-hint ${uid}-pending` : `${uid}-hint`"
                    :disabled="busy || disabled"
                    class="min-h-tap min-w-tap shrink-0 text-sm font-semibold text-accent disabled:opacity-50"
                    @click="undo"
                >
                    Undo
                </button>
                <span v-if="token !== null" :id="`${uid}-hint`" class="sr-only">Reverts this change</span>
            </div>
            <!-- Always inside a live region (TOAST_HOST or the one above), so no role="alert" of its own. -->
            <p v-if="shownError" data-toast-error class="mt-2 text-sm text-tone-warning">{{ shownError }}</p>
        </template>
    </div>
</template>
