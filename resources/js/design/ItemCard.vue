<script lang="ts">
/** The control on the right of an ItemCard (UI-002). A tap emits `action`. */
export type ItemCardStatus =
    /** Meals. `label` names the box for screen readers (UI-030). */
    | { kind: 'checkbox'; isOn: boolean; label: string }
    /** Meals on Today: the check-in box with its state's icon (MEAL-047). `label` is the server's state text. */
    | { kind: 'check-in'; state: 'not_checked_in' | 'on_track' | 'under' | 'over'; label: string; mealName: string }
    /** Workouts: Start or Done. */
    | { kind: 'button'; title: string }
    /** Weigh-ins: a value. */
    | { kind: 'value'; text: string };
</script>

<script setup lang="ts">
import { computed, useId } from 'vue';

// Meals, workouts, weigh-ins and busy periods share this card (UI-002), ported
// from rp-clone's ItemCard.swift. The icon and body are slots.
const props = defineProps<{
    title: string;
    time?: string;
    /** None for busy periods, which have no status control. */
    status?: ItemCardStatus;
    state?: 'loading' | 'error' | 'pending';
    disabled?: boolean;
}>();

const emit = defineEmits<{ action: [] }>();

const titleId = useId();

// The meal box: a shape for each state as well as a colour (MEAL-047, UI-030).
const checkBox = computed(() => {
    const status = props.status;
    if (status?.kind === 'checkbox') {
        return {
            checked: status.isOn,
            label: status.label,
            description: undefined,
            shape: status.isOn ? 'check' : 'empty',
            color: status.isOn ? 'text-accent' : 'text-secondary-text',
        };
    }
    if (status?.kind === 'check-in') {
        const look = {
            not_checked_in: { shape: 'empty', color: 'text-secondary-text' },
            on_track: { shape: 'check', color: 'text-tone-good' },
            under: { shape: 'down', color: 'text-tone-warning' },
            over: { shape: 'up', color: 'text-tone-warning' },
        }[status.state];
        return { checked: status.state !== 'not_checked_in', label: `${status.mealName} eaten`, description: status.label, ...look };
    }
    return null;
});

const paths: Record<string, string> = {
    check: 'M8 12.5l2.75 2.75L16 9.5',
    down: 'M12 7.5v9M8 12.5l4 4 4-4',
    up: 'M12 16.5v-9M8 11.5l4-4 4 4',
};

const isReady = computed(() => props.state !== 'loading' && props.state !== 'error');
const isPending = computed(() => props.state === 'pending');
</script>

<template>
    <article
        :aria-labelledby="titleId"
        :aria-busy="props.state === 'loading' ? 'true' : undefined"
        class="flex w-full flex-col gap-2.5 rounded-2xl bg-surface p-3.5 text-primary-text"
    >
        <div class="flex items-center gap-2.5">
            <span class="flex shrink-0 text-xl text-accent" aria-hidden="true"><slot name="icon" /></span>
            <h3 :id="titleId" class="min-w-0 flex-1 text-[1.0625rem] font-semibold">{{ props.title }}</h3>

            <template v-if="isReady">
                <span
                    v-if="props.time"
                    class="text-[0.9375rem] text-secondary-text"
                    :class="{ 'opacity-50': isPending }"
                    >{{ props.time }}</span
                >

                <button
                    v-if="checkBox"
                    type="button"
                    role="checkbox"
                    :aria-checked="checkBox.checked"
                    :aria-label="checkBox.label"
                    :aria-description="checkBox.description"
                    :disabled="props.disabled"
                    class="flex min-h-tap min-w-tap items-center justify-center disabled:opacity-50"
                    :class="checkBox.color"
                    @click="emit('action')"
                >
                    <svg viewBox="0 0 24 24" class="size-7" aria-hidden="true">
                        <rect
                            x="3.5"
                            y="3.5"
                            width="17"
                            height="17"
                            rx="3.5"
                            stroke="currentColor"
                            stroke-width="1.75"
                            :fill="checkBox.shape === 'empty' ? 'none' : 'currentColor'"
                        />
                        <path
                            v-if="checkBox.shape !== 'empty'"
                            :d="paths[checkBox.shape]"
                            class="stroke-surface"
                            fill="none"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                    </svg>
                </button>

                <button
                    v-else-if="props.status?.kind === 'button'"
                    type="button"
                    :disabled="props.disabled"
                    class="min-h-tap min-w-tap rounded-lg bg-accent/15 px-3 text-accent disabled:bg-background disabled:text-secondary-text"
                    @click="emit('action')"
                >
                    {{ props.status.title }}
                </button>

                <span
                    v-else-if="props.status?.kind === 'value'"
                    class="flex min-h-tap items-center text-[1.0625rem] font-semibold tabular-nums"
                    :class="{ 'opacity-50': isPending }"
                    >{{ props.status.text }}</span
                >
            </template>
        </div>

        <div v-if="props.state === 'loading'" class="flex flex-col gap-2">
            <span class="sr-only">Loading</span>
            <span class="h-4 w-full animate-pulse rounded bg-background" />
            <span class="h-4 w-2/3 animate-pulse rounded bg-background" />
        </div>
        <p v-else-if="props.state === 'error'" role="alert" class="text-secondary-text">Couldn't load this.</p>
        <template v-else>
            <div v-if="$slots.default" :class="{ 'opacity-50': isPending }"><slot /></div>
            <span v-if="isPending" class="text-sm text-secondary-text">Waiting to sync</span>
        </template>
    </article>
</template>
