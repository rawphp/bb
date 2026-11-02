<script lang="ts">
/**
 * One step as the server sends it, field for field (`onboarding_show`'s `step`),
 * so a screen passes the payload straight in: `<StepFlow :step="data.step">`.
 */
export interface StepFlowStep {
    /** "Step 2 of 4", or the server's text for a screen after the last step. */
    counter: string;
    title: string;
    /** "Next", or "Done" on the last step. */
    next_title: string;
    can_go_back: boolean;
    can_continue: boolean;
    /** Why Next is off, such as "Pick a goal to continue." */
    continue_hint?: string | null;
}
</script>

<script setup lang="ts">
import { computed, useId } from 'vue';

// Step counter, title, content, Back and Next (UI-007). Ported from rp-clone's
// StepFlow.swift. The server sends the counter, the Next title and whether the
// step can go back or continue (CAP-001), so the flow does no maths.
// VIEW-004 states take the same props as ItemCard and BottomSheet.
const props = withDefaults(
    defineProps<{
        step: StepFlowStep;
        /** The step is still loading: "Loading" shows in place of the content. */
        loading?: boolean;
        /** The step failed to load: this message shows in place of the content. */
        error?: string | null;
        /** A change is waiting to sync (OFF-008). The step stays usable. */
        pending?: boolean;
        disabled?: boolean;
    }>(),
    { loading: false, error: null, pending: false, disabled: false },
);

const emit = defineEmits<{ back: []; next: [] }>();

const titleId = useId();
const hintId = useId();
const blocked = computed(() => props.disabled || props.loading);
const backOff = computed(() => blocked.value || !props.step.can_go_back);
const nextOff = computed(() => blocked.value || !!props.error || !props.step.can_continue);
const hint = computed(() => (!blocked.value && !props.error && !props.step.can_continue ? props.step.continue_hint : null));

const button = 'flex min-h-tap flex-1 items-center justify-center rounded-xl px-4 font-semibold disabled:opacity-50';
</script>

<template>
    <div
        role="group"
        :aria-labelledby="titleId"
        :aria-busy="props.loading || undefined"
        :aria-disabled="props.disabled || undefined"
        class="flex flex-col gap-4 rounded-2xl bg-surface p-4"
        :class="{ 'opacity-50': props.disabled }"
    >
        <p class="text-sm text-secondary-text">{{ props.step.counter }}</p>
        <h2 :id="titleId" class="text-2xl font-bold text-primary-text">{{ props.step.title }}</h2>
        <p v-if="props.loading" class="animate-pulse text-secondary-text">Loading</p>
        <p v-else-if="props.error" role="alert" class="text-tone-warning">{{ props.error }}</p>
        <div v-else-if="$slots.default" class="w-full">
            <slot />
        </div>
        <p v-if="props.pending && !props.loading" role="status" class="text-sm text-secondary-text">Waiting to sync</p>
        <p v-if="hint" :id="hintId" class="text-sm text-secondary-text">{{ hint }}</p>
        <div class="flex gap-3">
            <button
                type="button"
                data-step="back"
                :class="[button, 'bg-accent/15 text-accent']"
                :disabled="backOff"
                @click="emit('back')"
            >
                Back
            </button>
            <button
                type="button"
                data-step="next"
                :class="[button, 'bg-accent text-on-accent']"
                :disabled="nextOff"
                :aria-describedby="hint ? hintId : undefined"
                @click="emit('next')"
            >
                {{ props.step.next_title }}
            </button>
        </div>
    </div>
</template>
