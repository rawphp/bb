<script setup lang="ts">
import { computed, ref } from 'vue';
import type { FoodSearchOutput } from '@/contracts/food_search';
import PendingMark from '@/design/PendingMark.vue';
import StatusPill from '@/design/StatusPill.vue';
import { useSwipeReveal } from '@/design/useSwipeReveal';

type Row = FoodSearchOutput['sections'][number]['rows'][number];
export type Weighing = 'raw' | 'cooked';

// One food: the picture that favorites it, the name and nutrition, a Raw/Cooked toggle
// when the food has a cooked value, and a check for the basket (FOOD-006, FOOD-022,
// FOOD-028). Ported from rp-clone's PantryRowView. A swipe to the left shows Favorite,
// as the SwiftUI swipe action does (useSwipeReveal).
const props = withDefaults(
    defineProps<{
        row: Row;
        weighing: Weighing;
        picked: boolean;
        /** Off when browsing: there is nothing to add the food to. */
        pickable?: boolean;
        /** The nutrition is the server's and waits on the outbox (OFF-008). */
        pending?: boolean;
        favoriteDisabled?: boolean;
        /** Add is running: the basket waits for it. */
        pickDisabled?: boolean;
    }>(),
    { pickable: true, pending: false, favoriteDisabled: false, pickDisabled: false },
);

const emit = defineEmits<{ pick: []; favorite: []; weighing: [weighing: Weighing] }>();

// The numbers shown are the server's for the way the food is weighed.
const nutrition = computed(() => (props.weighing === 'cooked' && props.row.cooked ? props.row.cooked : props.row.raw));
const nutritionText = computed(() => {
    const n = nutrition.value;
    return `${n.kcal} · ${n.protein} · ${n.fat} · ${n.carbs} ·`;
});

// The swipe action's width.
const ACTION = 96;
const root = ref<HTMLElement | null>(null);
const { open, drag, shift, pointerDown, pointerMove, pointerUp, pointerCancel } = useSwipeReveal(root, () => ACTION);

function favoriteFromSwipe(): void {
    open.value = false;
    emit('favorite');
}
</script>

<template>
    <li ref="root" data-test="food-row" class="relative overflow-hidden">
        <button
            type="button"
            data-test="swipe-favorite"
            class="absolute inset-y-0 right-0 flex items-center justify-center bg-macro-fat font-medium text-white"
            :class="open || drag !== null ? '' : 'invisible'"
            :style="{ width: `${ACTION}px` }"
            :aria-hidden="open ? undefined : 'true'"
            :tabindex="open ? 0 : -1"
            :disabled="favoriteDisabled"
            @click="favoriteFromSwipe"
        >
            {{ row.is_favorite ? 'Unfavorite' : 'Favorite' }}
        </button>
        <div
            data-test="swipe"
            class="relative flex touch-pan-y items-start gap-3 bg-background py-2"
            :class="drag === null ? 'transition-transform duration-200 motion-reduce:transition-none' : ''"
            :style="{ transform: `translateX(${shift}px)` }"
            @pointerdown="pointerDown"
            @pointermove="pointerMove"
            @pointerup="pointerUp"
            @pointercancel="pointerCancel"
        >
            <button
                type="button"
                data-test="favorite"
                class="relative flex min-h-tap min-w-tap shrink-0 items-center justify-center"
                :aria-label="row.is_favorite ? `Unfavorite ${row.name}` : `Favorite ${row.name}`"
                :aria-pressed="row.is_favorite"
                :disabled="favoriteDisabled"
                @click="emit('favorite')"
            >
                <span class="flex size-11 items-center justify-center rounded-[10px] bg-surface text-accent">
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M7 3v8M5 3v5a2 2 0 0 0 4 0V3M7 11v10M17 3c-1.7 0-3 2.2-3 5s1.3 4 3 4v9M17 3v18" />
                    </svg>
                </span>
                <svg v-if="row.is_favorite" data-test="favorite-star" aria-hidden="true" viewBox="0 0 24 24" class="absolute right-0 bottom-0 size-4 text-macro-fat" fill="currentColor">
                    <path d="m12 2.5 2.9 6 6.6.9-4.8 4.6 1.2 6.5L12 17.4l-5.9 3.1 1.2-6.5-4.8-4.6 6.6-.9Z" />
                </svg>
            </button>

            <div class="min-w-0 flex-1 space-y-1.5">
                <component
                    :is="pickable ? 'button' : 'div'"
                    :type="pickable ? 'button' : undefined"
                    :disabled="pickable && pickDisabled ? true : undefined"
                    class="block w-full text-left"
                    @click="pickable && emit('pick')"
                >
                    <span class="flex flex-wrap items-center gap-1.5">
                        <span data-test="food-name" class="text-primary-text">{{ row.name }}</span>
                        <StatusPill v-if="row.is_in_meal" text="In meal" tone="info" />
                    </span>
                    <span v-if="row.detail" class="block text-sm text-secondary-text">{{ row.detail }}</span>
                    <span data-test="nutrition" class="block text-[0.8125rem] text-secondary-text tabular-nums">
                        <PendingMark v-if="pending" derived>{{ nutritionText }} <span class="whitespace-nowrap">{{ row.basis_text }}</span></PendingMark>
                        <template v-else>{{ nutritionText }} <span class="whitespace-nowrap">{{ row.basis_text }}</span></template>
                    </span>
                </component>
                <div v-if="row.cooked" role="group" :aria-label="`${row.name} weighed`" class="flex max-w-50 rounded-full bg-secondary-text/15 p-0.5">
                    <button
                        v-for="option in (['raw', 'cooked'] as const)"
                        :key="option"
                        type="button"
                        :data-test="`weighing-${option}`"
                        :aria-pressed="weighing === option"
                        class="min-h-9 flex-1 rounded-full text-sm font-medium"
                        :class="weighing === option ? 'bg-surface text-primary-text shadow-sm ring-1 ring-secondary-text/25' : 'text-secondary-text'"
                        @click="emit('weighing', option)"
                    >
                        {{ option === 'raw' ? 'Raw' : 'Cooked' }}
                    </button>
                </div>
            </div>

            <button
                v-if="pickable"
                type="button"
                data-test="pick"
                class="flex min-h-tap min-w-tap shrink-0 items-center justify-center"
                :aria-label="picked ? `Remove ${row.name} from meal` : `Add ${row.name} to meal`"
                :aria-pressed="picked"
                :disabled="pickDisabled"
                @click="emit('pick')"
            >
                <svg v-if="picked" aria-hidden="true" viewBox="0 0 24 24" class="size-7 text-accent">
                    <circle cx="12" cy="12" r="10" fill="currentColor" />
                    <path d="m7.5 12.5 3 3 6-7" fill="none" stroke="var(--color-on-accent)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <svg v-else aria-hidden="true" viewBox="0 0 24 24" class="size-7 text-secondary-text" fill="none" stroke="currentColor" stroke-width="1.6">
                    <circle cx="12" cy="12" r="10" />
                </svg>
            </button>
        </div>
    </li>
</template>
