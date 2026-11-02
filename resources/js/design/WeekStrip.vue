<script lang="ts">
/**
 * One day as the server sends it, field for field (`week_show`'s `days`),
 * so a screen passes the payload straight in: `<WeekStrip :days="data.days">`.
 */
export interface WeekStripDay {
    /** The day's date, "2026-10-27". `select` emits it. */
    date: string;
    /** "M", "T"... */
    letter: string;
    /** "27" */
    number: string;
    /** "2600", or "–" before the plan (SCH-021). */
    calories_text: string;
    /** SCH-020: today carries a ring when it is not the selected day. */
    is_today: boolean;
    is_selected: boolean;
    /** SCH-021: the green dot. */
    is_complete: boolean;
    /** SCH-022: the dumbbell. */
    has_training: boolean;
    /** SCH-021: before the plan starts, only the dash shows. */
    is_before_plan: boolean;
}
</script>

<script setup lang="ts">
import { toneIcons } from './toneIcons';

// Day letter, date, calories, completion dot and training icon (UI-001), ported from rp-clone's WeekStrip.swift.
const props = withDefaults(
    defineProps<{
        days: WeekStripDay[];
        /** Names the strip for screen readers (UI-030). */
        label?: string;
        /** Days are not pickable; with no days yet, placeholder days show. */
        loading?: boolean;
        error?: string | null;
        /** The calories are server-derived and an outbox entry is waiting (OFF-008). */
        pending?: boolean;
        disabled?: boolean;
    }>(),
    { label: 'Week', loading: false, error: null, pending: false, disabled: false },
);

const emit = defineEmits<{
    select: [id: string];
    /** -1 for a swipe right (the week before), 1 for a swipe left (the week after) (SCH-020). */
    swipe: [step: -1 | 1];
}>();

function spokenLabel(day: WeekStripDay): string {
    const parts = [`${day.letter} ${day.number}`];
    if (!day.is_before_plan) parts.push(`${day.calories_text} calories`);
    if (day.is_today) parts.push('today');
    if (!day.is_before_plan) parts.push(day.is_complete ? 'completed' : 'not completed');
    if (day.has_training && !day.is_before_plan) parts.push('training day');
    if (props.pending) parts.push('waiting to sync');
    return parts.join(', ');
}

let start: { x: number; y: number } | null = null;
let swiped = false;

function down(event: PointerEvent): void {
    start = { x: event.clientX, y: event.clientY };
    swiped = false;
}

// A drag of at least 30px that is mostly horizontal changes the week, as in rp-clone.
function up(event: PointerEvent): void {
    if (!start) return;
    const dx = event.clientX - start.x;
    const dy = event.clientY - start.y;
    start = null;
    if (props.loading || props.disabled || Math.hypot(dx, dy) < 30 || Math.abs(dx) <= Math.abs(dy) * 1.5) return;
    // A mouse drag clicks the element it ended on in the same task as this
    // pointerup. A touch drag sends no click, so the flag lapses before a later
    // tap or a keyboard press.
    swiped = true;
    setTimeout(() => (swiped = false));
    emit('swipe', dx < 0 ? 1 : -1);
}

// The click that ends a swipe is not a tap on a day, wherever in the strip it lands.
function endSwipe(event: MouseEvent): void {
    if (!swiped) return;
    swiped = false;
    event.stopPropagation();
}
</script>

<template>
    <div>
        <div
            role="group"
            :aria-label="label"
            :aria-busy="loading ? 'true' : undefined"
            class="flex touch-pan-y gap-0.5 rounded-2xl bg-surface p-2"
            @pointerdown="down"
            @pointerup="up"
            @pointercancel="start = null"
            @click.capture="endSwipe"
        >
            <template v-if="loading && days.length === 0">
                <div
                    v-for="n in 7"
                    :key="n"
                    data-placeholder
                    aria-hidden="true"
                    class="min-h-tap flex-1 animate-pulse rounded-xl bg-secondary-text/15"
                />
            </template>
            <button
                v-for="day in days"
                :key="day.date"
                type="button"
                :aria-label="spokenLabel(day)"
                :aria-pressed="day.is_selected"
                :aria-current="day.is_today ? 'date' : undefined"
                :disabled="loading || disabled"
                class="flex min-h-tap min-w-0 flex-1 flex-col items-center gap-1 disabled:opacity-60"
                @click="emit('select', day.date)"
            >
                <span class="text-xs font-semibold text-secondary-text">{{ day.letter }}</span>
                <span
                    data-date
                    class="flex size-8 items-center justify-center rounded-full text-base font-semibold"
                    :class="
                        day.is_selected
                            ? 'bg-accent text-on-accent'
                            : day.is_today
                              ? 'text-primary-text ring-2 ring-inset ring-accent'
                              : 'text-primary-text'
                    "
                >
                    {{ day.number }}
                </span>
                <span data-calories class="text-xs tabular-nums text-secondary-text" :class="{ 'opacity-50': pending }">
                    {{ day.calories_text }}
                </span>
                <svg
                    aria-hidden="true"
                    viewBox="0 0 24 24"
                    class="size-3.5"
                    :data-completion="day.is_complete ? 'complete' : 'open'"
                    :class="[day.is_complete ? 'text-tone-good' : 'text-secondary-text', { invisible: day.is_before_plan }]"
                >
                    <path v-if="day.is_complete" :d="toneIcons.good" fill="currentColor" fill-rule="evenodd" />
                    <circle v-else cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2" />
                </svg>
                <svg
                    aria-hidden="true"
                    data-training
                    viewBox="0 0 24 24"
                    class="size-3 text-accent"
                    :class="{ invisible: !day.has_training || day.is_before_plan }"
                    fill="currentColor"
                >
                    <path d="M1 10h2V7h3v10H3v-3H1zm22 0h-2V7h-3v10h3v-3h2zM7 5h3v14H7zm7 0h3v14h-3zm-4 6h4v2h-4z" />
                </svg>
            </button>
        </div>
        <p v-if="pending" class="mt-1 text-xs text-secondary-text" aria-hidden="true">Waiting to sync</p>
        <p v-if="error" role="alert" class="mt-2 text-sm text-tone-warning">{{ error }}</p>
    </div>
</template>
