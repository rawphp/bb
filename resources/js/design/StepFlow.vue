<script setup lang="ts">
import { computed, useId } from 'vue';

// Step counter, title, content, Back and Next (UI-007). Ported from rp-clone's
// StepFlow.swift. The server sends the counter, the Next title and whether the
// step can go back or continue (CAP-001), so the flow does no maths.
const props = withDefaults(
    defineProps<{
        // "Step 2 of 4", or the server's text for a screen after the last step.
        counter: string;
        title: string;
        // "Next", or "Done" on the last step.
        nextTitle: string;
        canGoBack: boolean;
        // Off while the step cannot continue.
        canContinue?: boolean;
        state?: 'loading' | 'error' | 'pending';
        disabled?: boolean;
    }>(),
    { canContinue: true },
);

const emit = defineEmits<{ back: []; next: [] }>();

const titleId = useId();
const blocked = computed(() => props.disabled || props.state === 'loading');
const backOff = computed(() => blocked.value || !props.canGoBack);
const nextOff = computed(() => blocked.value || props.state === 'error' || !props.canContinue);

const button = 'flex min-h-tap flex-1 items-center justify-center rounded-xl px-4 font-semibold disabled:opacity-50';
</script>

<template>
    <div
        role="group"
        :aria-labelledby="titleId"
        :aria-busy="props.state === 'loading' || undefined"
        :aria-disabled="props.disabled || undefined"
        class="flex flex-col gap-4 rounded-2xl bg-surface p-4"
        :class="{ 'opacity-50': props.disabled }"
    >
        <p class="text-sm text-secondary-text">{{ props.counter }}</p>
        <h2 :id="titleId" class="text-2xl font-bold text-primary-text">{{ props.title }}</h2>
        <p v-if="props.state === 'loading'" class="animate-pulse text-secondary-text">Loading</p>
        <p v-else-if="props.state === 'error'" role="alert" class="text-primary-text">Couldn't load this step</p>
        <div v-else-if="$slots.default" class="w-full">
            <slot />
        </div>
        <p v-if="props.state === 'pending'" role="status" class="text-sm text-secondary-text">Waiting to sync</p>
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
                @click="emit('next')"
            >
                {{ props.nextTitle }}
            </button>
        </div>
    </div>
</template>
