<script lang="ts">
/** One option in a ChoiceList. */
export interface Choice<T extends string | number> {
    id: T;
    title: string;
    detail?: string;
    isRecommended?: boolean;
    /** When false the row shows dimmed and cannot be picked; `detail` says why. */
    isEnabled?: boolean;
}
</script>

<script setup lang="ts" generic="T extends string | number">
import { computed, useId } from 'vue';
import PendingMark from './PendingMark.vue';
import Skeleton from './Skeleton.vue';

// Single-choice list of full-width rows, one selected (UI-006), ported from rp-clone's ChoiceList.swift.
const props = withDefaults(
    defineProps<{
        /** Names the group for screen readers (UI-030). */
        label: string;
        choices: Choice<T>[];
        modelValue: T | null;
        /** Rows are not pickable; with no choices yet, placeholder rows show. */
        loading?: boolean;
        error?: string | null;
        /** The selection is the user's own input waiting in the outbox (OFF-009). */
        pending?: boolean;
        disabled?: boolean;
    }>(),
    { loading: false, error: null, pending: false, disabled: false },
);

const emit = defineEmits<{ 'update:modelValue': [id: T] }>();

const uid = useId();

function enabled(choice: Choice<T>): boolean {
    return choice.isEnabled !== false;
}

function selected(choice: Choice<T>): boolean {
    return enabled(choice) && props.modelValue === choice.id;
}

function describedBy(choice: Choice<T>, index: number): string | undefined {
    const ids = [choice.detail ? `${uid}-${index}` : '', props.pending && selected(choice) ? `${uid}-${index}-pending` : ''];
    return ids.filter(Boolean).join(' ') || undefined;
}

// While an error shows, re-picking the selected row emits again so the user can retry a failed save.
function pick(choice: Choice<T>): void {
    if (props.modelValue === choice.id && !props.error) {
        return;
    }
    emit('update:modelValue', choice.id);
}

// The group is one tab stop (WAI-ARIA radio pattern): the selected row, else the first row that can be picked.
const tabStop = computed(() => {
    const index = props.choices.findIndex(selected);
    return index >= 0 ? index : props.choices.findIndex(enabled);
});

const rows: HTMLButtonElement[] = [];

const steps: Record<string, number> = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1 };

// Arrow keys move focus to the next row that can be picked, wrapping around, and pick it.
function move(event: KeyboardEvent, from: number): void {
    const step = steps[event.key];
    if (step === undefined) {
        return;
    }
    event.preventDefault();
    const count = props.choices.length;
    for (let offset = 1; offset <= count; offset++) {
        const index = (((from + step * offset) % count) + count) % count;
        const choice = props.choices[index]!;
        if (enabled(choice)) {
            rows[index]?.focus();
            pick(choice);
            return;
        }
    }
}
</script>

<template>
    <div>
        <div role="radiogroup" :aria-label="label" :aria-busy="loading ? 'true' : undefined" class="flex flex-col gap-2">
            <Skeleton v-if="loading && choices.length === 0" shape="row" :count="3" />
            <button
                v-for="(choice, index) in choices"
                :key="choice.id"
                :ref="(el) => (rows[index] = el as HTMLButtonElement)"
                type="button"
                role="radio"
                :aria-checked="selected(choice)"
                :aria-label="choice.isRecommended ? `${choice.title}, recommended` : choice.title"
                :aria-describedby="describedBy(choice, index)"
                :disabled="loading || disabled || !enabled(choice)"
                :tabindex="index === tabStop ? 0 : -1"
                class="flex min-h-tap w-full items-center gap-3 rounded-xl border bg-surface px-3.5 py-1.5 text-left"
                :class="[
                    selected(choice) ? 'border-accent ring-1 ring-inset ring-accent' : 'border-secondary-text/25',
                    loading || disabled || !enabled(choice) ? 'opacity-60' : '',
                ]"
                @click="pick(choice)"
                @keydown="move($event, index)"
            >
                <svg
                    aria-hidden="true"
                    viewBox="0 0 24 24"
                    class="size-6 shrink-0"
                    :class="selected(choice) ? 'text-accent' : 'text-secondary-text'"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.75"
                >
                    <template v-if="!enabled(choice)">
                        <circle cx="12" cy="12" r="9" />
                        <path d="M5.6 5.6l12.8 12.8" />
                    </template>
                    <template v-else-if="selected(choice)">
                        <circle cx="12" cy="12" r="10" fill="currentColor" stroke="none" />
                        <path d="M7.5 12.5l3 3 6-6.5" class="stroke-on-accent" stroke-width="2.25" />
                    </template>
                    <circle v-else cx="12" cy="12" r="9" />
                </svg>
                <span class="flex min-w-0 grow flex-col gap-0.5">
                    <span class="text-base text-primary-text">{{ choice.title }}</span>
                    <span v-if="choice.detail" :id="`${uid}-${index}`" class="whitespace-pre-line text-sm text-secondary-text">
                        {{ choice.detail }}
                    </span>
                    <PendingMark v-if="pending && selected(choice)" :id="`${uid}-${index}-pending`" />
                </span>
                <span
                    v-if="choice.isRecommended"
                    class="ml-2 shrink-0 rounded-full border border-accent px-2 py-0.5 text-xs font-semibold text-accent"
                >
                    Recommended
                </span>
            </button>
        </div>
        <p v-if="error" role="alert" class="mt-2 text-sm text-tone-warning">{{ error }}</p>
    </div>
</template>
