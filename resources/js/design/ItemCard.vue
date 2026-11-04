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
import CheckInBox from './CheckInBox.vue';
import { checkInLook, type CheckInShape } from './checkInLook';

// Meals, workouts, weigh-ins and busy periods share this card (UI-002), ported
// from rp-clone's ItemCard.swift. The icon and body are slots.
// VIEW-004 states take the same props as HelpButton and ChoiceList.
const props = withDefaults(
    defineProps<{
        title: string;
        time?: string;
        /** None for busy periods, which have no status control. */
        status?: ItemCardStatus;
        /** The body is still loading: a busy placeholder shows in its place. */
        loading?: boolean;
        /** The body failed to load: this message shows in its place. */
        error?: string | null;
        /** A change is waiting to sync (OFF-008). */
        pending?: boolean;
        disabled?: boolean;
    }>(),
    { loading: false, error: null, pending: false, disabled: false },
);

const emit = defineEmits<{ action: [] }>();

const titleId = useId();

// The meal box: a shape for each state as well as a colour (MEAL-047, UI-030).
const checkBox = computed(() => {
    const status = props.status;
    if (status?.kind === 'checkbox') {
        const shape: CheckInShape = status.isOn ? 'check' : 'empty';
        return {
            checked: status.isOn,
            label: status.label,
            description: undefined,
            shape,
            color: status.isOn ? 'text-accent' : 'text-secondary-text',
        };
    }
    if (status?.kind === 'check-in') {
        const look = checkInLook[status.state];
        return { checked: status.state !== 'not_checked_in', label: `${status.mealName} eaten`, description: status.label, ...look };
    }
    return null;
});

const isReady = computed(() => !props.loading && !props.error);
const isPending = computed(() => isReady.value && props.pending);
</script>

<template>
    <article
        :aria-labelledby="titleId"
        :aria-busy="props.loading ? 'true' : undefined"
        class="flex w-full flex-col gap-2.5 rounded-2xl bg-surface p-3.5 text-primary-text"
    >
        <div class="flex items-center gap-2.5">
            <span class="flex shrink-0 text-xl text-accent" aria-hidden="true"><slot name="icon" /></span>
            <h3 :id="titleId" class="min-w-0 flex-1 text-base font-semibold">{{ props.title }}</h3>

            <template v-if="isReady">
                <span
                    v-if="props.time"
                    class="text-sm text-secondary-text"
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
                    <CheckInBox :shape="checkBox.shape" />
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
                    class="flex min-h-tap items-center text-base font-semibold tabular-nums"
                    :class="{ 'opacity-50': isPending }"
                    >{{ props.status.text }}</span
                >
            </template>
        </div>

        <div v-if="props.loading" class="flex flex-col gap-2">
            <span class="sr-only">Loading</span>
            <span class="h-4 w-full animate-pulse rounded bg-background" />
            <span class="h-4 w-2/3 animate-pulse rounded bg-background" />
        </div>
        <p v-else-if="props.error" role="alert" class="text-tone-warning">{{ props.error }}</p>
        <template v-else>
            <div v-if="$slots.default" :class="{ 'opacity-50': isPending }"><slot /></div>
            <span v-if="isPending" class="text-sm text-secondary-text">Waiting to sync</span>
        </template>
    </article>
</template>
