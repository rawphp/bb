<script setup lang="ts">
import { computed, ref } from 'vue';
import type { TodayShowOutput } from '@/contracts/today_show';
import ItemCard, { type ItemCardStatus } from '@/design/ItemCard.vue';
import StatusPill from '@/design/StatusPill.vue';
import { toneClasses } from '@/design/tones';
import { toneIcons } from '@/design/toneIcons';
import { useSwipeReveal } from '@/design/useSwipeReveal';

// SCH-002, SCH-008, SCH-009, MEAL-047: a meal with its check-in box. A tap on the card
// opens the meal (NAV-002), a swipe left shows Edit macros and Delete, and a long press
// opens the meal menu (SCH-010). Ported from rp-clone's TodayMealCard; MacroLine is inline.
const props = withDefaults(
    defineProps<{
        meal: TodayShowOutput['meals'][number];
        condensed: boolean;
        /** The server's numbers wait on the outbox (OFF-008). */
        pending?: boolean;
        /** OFF-009: the user's own check-in (true) or its removal (false) waiting to sync; null when none waits. */
        pendingCheck?: boolean | null;
    }>(),
    { pending: false, pendingCheck: null },
);

const emit = defineEmits<{ toggle: []; open: []; menu: []; edit: []; delete: [] }>();

// Two buttons of 88px behind the card.
const TRAY = 176;
const root = ref<HTMLElement | null>(null);
const swipe = useSwipeReveal(root, () => TRAY);

const status = computed<ItemCardStatus>(() =>
    props.pendingCheck === null
        ? { kind: 'check-in', state: props.meal.check_in.state, label: props.meal.check_in.label, mealName: props.meal.name }
        : { kind: 'checkbox', isOn: props.pendingCheck, label: `${props.meal.name} eaten` },
);

// A tap on the box or a tray button is not a tap on the card.
function open(event: MouseEvent): void {
    if ((event.target as HTMLElement).closest('button')) return;
    if (swipe.open.value) return;
    emit('open');
}

function act(name: 'edit' | 'delete'): void {
    swipe.open.value = false;
    if (name === 'edit') emit('edit');
    else emit('delete');
}
</script>

<template>
    <div ref="root" class="relative overflow-hidden rounded-2xl" :data-meal="meal.id">
        <div class="absolute inset-y-0 right-0 flex" :style="{ width: `${TRAY}px` }" :aria-hidden="swipe.open.value ? undefined : 'true'">
            <button
                type="button"
                class="flex-1 bg-accent text-sm font-semibold text-on-accent"
                :tabindex="swipe.open.value ? undefined : -1"
                data-test="swipe-edit"
                @click="act('edit')"
            >
                Edit macros
            </button>
            <button
                type="button"
                class="flex-1 bg-tone-warning-bg text-sm font-semibold text-tone-warning"
                :tabindex="swipe.open.value ? undefined : -1"
                data-test="swipe-delete"
                @click="act('delete')"
            >
                Delete
            </button>
        </div>
        <div
            class="relative touch-pan-y bg-background"
            :class="swipe.drag.value === null ? 'transition-transform' : ''"
            :style="{ transform: `translateX(${swipe.shift.value}px)` }"
            data-test="meal-card"
            @pointerdown="swipe.pointerDown"
            @pointermove="swipe.pointerMove"
            @pointerup="swipe.pointerUp"
            @pointercancel="swipe.pointerCancel"
            @click="open"
            @contextmenu.prevent="emit('menu')"
        >
            <ItemCard
                :title="meal.name"
                :time="(condensed ? meal.condensed_time_text : meal.time_text) ?? undefined"
                :status="status"
                :pending="pending || pendingCheck !== null"
                @action="emit('toggle')"
            >
                <template #icon>
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round">
                        <path d="M7 3v8m-2-8v5a2 2 0 0 0 4 0V3m-2 8v10M17 3c-1.7 1-2.5 3-2.5 6s1 4 2.5 4v8" />
                    </svg>
                </template>
                <div class="flex flex-col gap-2">
                    <!-- DAY-003: shown in the condensed card too. -->
                    <span v-if="meal.badges.length" class="flex flex-wrap gap-1.5">
                        <StatusPill v-for="badge in meal.badges" :key="badge.text" :text="badge.text" :tone="badge.tone" />
                    </span>
                    <template v-if="!condensed">
                        <p>
                            <span class="sr-only">{{ meal.macros.label }}</span>
                            <span class="flex gap-3 text-sm font-medium tabular-nums" aria-hidden="true" data-test="macros">
                                <span class="text-macro-calories">{{ meal.macros.kcal }}</span>
                                <span class="text-macro-protein">{{ meal.macros.protein }}</span>
                                <span class="text-macro-fat">{{ meal.macros.fat }}</span>
                                <span class="text-macro-carbs">{{ meal.macros.carbs }}</span>
                            </span>
                        </p>
                        <p v-if="meal.planned_text" class="text-sm text-secondary-text">{{ meal.planned_text }}</p>
                        <p v-if="meal.eaten_text" class="text-sm text-secondary-text">{{ meal.eaten_text }}</p>
                        <p v-if="meal.plan_banner" class="flex items-center gap-2 rounded-xl p-2 text-sm font-semibold" :class="toneClasses.info" data-test="plan-banner">
                            <svg viewBox="0 0 24 24" class="size-4 shrink-0" aria-hidden="true"><path :d="toneIcons.info" fill="currentColor" fill-rule="evenodd" /></svg>
                            <span>{{ meal.plan_banner }}</span>
                        </p>
                    </template>
                </div>
            </ItemCard>
        </div>
    </div>
</template>
