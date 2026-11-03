<script setup lang="ts">
import { ref } from 'vue';
import type { MealShowOutput } from '@/contracts/meal_show';
import PendingMark from '@/design/PendingMark.vue';
import { useSwipeReveal } from '@/design/useSwipeReveal';

// The layout MealFoodRowView and MealRecipeRowView share (MEAL-004, MEAL-005, MEAL-008,
// RCP-002): the name, a lock, the amount with − and +, the macro line, and Delete behind a
// swipe left. Each row fills in its own name block and amount control. `pending` marks the
// server's macros as waiting to sync (OFF-008).
type Entry = MealShowOutput['entries'][number];

const props = defineProps<{ entry: Entry; busy: boolean; pending: boolean }>();

const emit = defineEmits<{ lock: []; minus: []; plus: []; delete: [] }>();

defineSlots<{ title(): unknown; amount(): unknown }>();

const root = ref<HTMLElement | null>(null);
const tray = ref<HTMLElement | null>(null);
const { open, drag, shift, pointerDown, pointerMove, pointerUp, pointerCancel } = useSwipeReveal(root, () => tray.value?.offsetWidth ?? 0, () => !props.busy);
</script>

<template>
    <div ref="root" class="relative overflow-hidden rounded-2xl bg-surface">
        <!-- Focus on Delete slides the row open, so a keyboard reaches what a swipe shows. -->
        <div ref="tray" class="absolute inset-y-0 right-0 flex" data-swipe-actions>
            <button
                type="button"
                class="min-w-20 bg-tone-warning-bg px-3 text-sm font-semibold text-tone-warning select-none"
                :aria-label="`Delete ${entry.name}`"
                :disabled="busy"
                data-test="delete"
                @focus="open = true"
                @click="emit('delete')"
            >
                Delete
            </button>
        </div>
        <div
            class="relative flex touch-pan-y flex-col gap-1 bg-surface p-3.5"
            :class="drag === null ? 'transition-transform duration-300 motion-reduce:transition-none' : ''"
            :style="shift ? { transform: `translateX(${shift}px)` } : undefined"
            @pointerdown="pointerDown"
            @pointermove="pointerMove"
            @pointerup="pointerUp"
            @pointercancel="pointerCancel"
        >
            <div class="flex items-center gap-2">
                <slot name="title" />
                <button
                    type="button"
                    class="flex min-h-tap min-w-tap items-center justify-center"
                    :class="entry.is_locked ? 'text-accent' : 'text-secondary-text'"
                    :aria-label="entry.is_locked ? `Unlock ${entry.name}` : `Lock ${entry.name}`"
                    :aria-pressed="entry.is_locked"
                    :disabled="busy"
                    data-test="lock"
                    @click="emit('lock')"
                >
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true">
                        <rect x="5" y="11" width="14" height="10" rx="2" :fill="entry.is_locked ? 'currentColor' : 'none'" />
                        <path :d="entry.is_locked ? 'M8 11V7a4 4 0 0 1 8 0v4' : 'M8 11V7a4 4 0 0 1 7.5-2'" />
                    </svg>
                </button>
            </div>
            <div class="flex items-center gap-1">
                <slot name="amount" />
                <span class="flex-1" />
                <button type="button" class="flex min-h-tap min-w-tap items-center justify-center text-accent" :aria-label="`Decrease ${entry.name}`" :disabled="busy" data-test="minus" @click="emit('minus')">
                    <svg viewBox="0 0 24 24" class="size-7" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><circle cx="12" cy="12" r="10" /><path d="M8 12h8" /></svg>
                </button>
                <button type="button" class="flex min-h-tap min-w-tap items-center justify-center text-accent" :aria-label="`Increase ${entry.name}`" :disabled="busy" data-test="plus" @click="emit('plus')">
                    <svg viewBox="0 0 24 24" class="size-7" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><circle cx="12" cy="12" r="10" /><path d="M8 12h8M12 8v8" /></svg>
                </button>
            </div>
            <p class="flex flex-wrap items-baseline gap-x-3 text-sm font-medium tabular-nums" data-test="macros">
                <span class="flex gap-3" :class="{ 'opacity-60': pending }">
                    <span class="text-macro-calories">{{ entry.macros.kcal }}</span>
                    <span class="text-macro-protein">{{ entry.macros.protein }}</span>
                    <span class="text-macro-fat">{{ entry.macros.fat }}</span>
                    <span class="text-macro-carbs">{{ entry.macros.carbs }}</span>
                </span>
                <PendingMark v-if="pending" />
            </p>
        </div>
    </div>
</template>
