<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import { CapabilityError, write } from '@/app/call';

// A message with Undo, for every automatic change (UI-010), ported from rp-clone's Toast.swift.
const props = withDefaults(
    defineProps<{
        /** Written by the server (CAP-010). Empty draws nothing. */
        message: string;
        /** The write's undo token (CAP-013). Without one there is no Undo. */
        token?: string | null;
        loading?: boolean;
        error?: string | null;
        /** The change is waiting in the outbox (OFF-008). */
        pending?: boolean;
        disabled?: boolean;
    }>(),
    { token: null, loading: false, error: null, pending: false, disabled: false },
);

const emit = defineEmits<{ undone: [result: unknown] }>();

const uid = useId();
const undoing = ref(false);
const failure = ref<string | null>(null);

const busy = computed(() => props.loading || undoing.value);
const shownError = computed(() => failure.value ?? props.error);

// The server writes the refusal message (CAP-012); with no answer at all, say so plainly.
function messageOf(error: unknown): string {
    const body = error instanceof CapabilityError ? (error.body as { error?: { message?: unknown } } | null) : null;
    const text = body?.error?.message;

    return typeof text === 'string' && text !== '' ? text : "Couldn't undo. Try again.";
}

async function undo(): Promise<void> {
    if (props.token === null || busy.value || props.disabled) {
        return;
    }
    undoing.value = true;
    failure.value = null;
    try {
        emit('undone', await write('undo_apply', { token: props.token }));
    } catch (error) {
        failure.value = messageOf(error);
    } finally {
        undoing.value = false;
    }
}
</script>

<template>
    <div v-if="message" role="status" :aria-busy="busy ? 'true' : undefined">
        <div class="flex items-center gap-3 rounded-[14px] border border-secondary-text/25 bg-surface pr-2 pl-4">
            <span class="flex min-w-0 grow flex-col gap-0.5 py-2">
                <span class="text-sm text-primary-text">{{ message }}</span>
                <span v-if="pending" :id="`${uid}-pending`" class="text-xs text-secondary-text">Waiting to sync</span>
            </span>
            <button
                v-if="token !== null"
                type="button"
                :aria-describedby="pending ? `${uid}-hint ${uid}-pending` : `${uid}-hint`"
                :disabled="busy || disabled"
                class="min-h-tap min-w-tap shrink-0 text-sm font-semibold text-accent"
                :class="busy || disabled ? 'opacity-60' : ''"
                @click="undo"
            >
                Undo
            </button>
            <span v-if="token !== null" :id="`${uid}-hint`" class="sr-only">Reverts this change</span>
        </div>
        <p v-if="shownError" role="alert" class="mt-2 text-sm text-tone-warning">{{ shownError }}</p>
    </div>
</template>
