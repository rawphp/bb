<script setup lang="ts">
import { ref } from 'vue';
import type { ShoppingShowOutput } from '@/contracts/shopping_show';
import PendingMark from '@/design/PendingMark.vue';
import { useSwipeReveal } from '@/design/useSwipeReveal';

type Row = ShoppingShowOutput['sections'][number]['rows'][number];

// One line of the shopping list, ported from rp-clone's ShoppingRowView: a tick, the name
// and the amount to buy. A tap ticks it. An item the user added swipes left to Delete,
// as the SwiftUI swipe action does (useSwipeReveal). Delete sits behind the line, so Tab
// reaches it and focus slides the line aside.
const props = withDefaults(
    defineProps<{
        row: Row;
        /** The tick shown: the server's, or the user's own while it waits to sync. */
        ticked: boolean;
        /** The tick is the user's own, waiting in the outbox (OFF-009). */
        tickPending?: boolean;
        /** The amount is the server's and waits on the outbox (OFF-008). */
        pending?: boolean;
        disabled?: boolean;
    }>(),
    { tickPending: false, pending: false, disabled: false },
);

const emit = defineEmits<{ tick: []; delete: [] }>();

// The swipe action's width.
const ACTION = 96;
const root = ref<HTMLElement | null>(null);
const { open, drag, shift, pointerDown, pointerMove, pointerUp, pointerCancel } = useSwipeReveal(
    root,
    () => ACTION,
    () => props.row.is_manual && !props.disabled,
);

function remove(): void {
    open.value = false;
    emit('delete');
}
</script>

<template>
    <li ref="root" class="relative overflow-hidden">
        <button
            v-if="row.is_manual"
            type="button"
            data-test="delete"
            class="absolute inset-y-0 right-0 flex items-center justify-center bg-tone-warning-bg font-medium text-tone-warning"
            :style="{ width: `${ACTION}px` }"
            :aria-label="`Delete ${row.name}`"
            :disabled="disabled"
            @focus="open = true"
            @click="remove"
        >
            Delete
        </button>
        <div
            class="relative touch-pan-y bg-surface"
            :class="drag === null ? 'transition-transform duration-200 motion-reduce:transition-none' : ''"
            :style="{ transform: `translateX(${shift}px)` }"
            @pointerdown="pointerDown"
            @pointermove="pointerMove"
            @pointerup="pointerUp"
            @pointercancel="pointerCancel"
        >
            <button
                type="button"
                data-test="tick"
                class="flex min-h-tap w-full items-center gap-3 px-4 py-3 text-left active:opacity-70 disabled:opacity-60"
                :aria-pressed="ticked"
                :disabled="disabled"
                @click="emit('tick')"
            >
                <svg v-if="ticked" aria-hidden="true" viewBox="0 0 24 24" class="size-6 shrink-0 text-accent">
                    <circle cx="12" cy="12" r="10" fill="currentColor" />
                    <path d="m7.5 12.5 3 3 6-7" fill="none" stroke="var(--color-on-accent)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <svg v-else aria-hidden="true" viewBox="0 0 24 24" class="size-6 shrink-0 text-secondary-text" fill="none" stroke="currentColor" stroke-width="1.6">
                    <circle cx="12" cy="12" r="10" />
                </svg>
                <span class="min-w-0 flex-1">
                    <span data-test="name" :class="ticked ? 'text-secondary-text line-through' : 'text-primary-text'">{{ row.name }}</span>
                    <PendingMark v-if="tickPending" class="ml-1.5" />
                </span>
                <span
                    v-if="row.amount_text"
                    data-test="amount"
                    class="shrink-0 text-[0.9375rem] text-secondary-text tabular-nums"
                    :class="{ 'line-through': ticked }"
                >
                    <PendingMark v-if="pending" derived>{{ row.amount_text }}</PendingMark>
                    <template v-else>{{ row.amount_text }}</template>
                </span>
            </button>
        </div>
    </li>
</template>
