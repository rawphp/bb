<script setup lang="ts">
import { ref } from 'vue';

// A − and + pair for a whole number, as SwiftUI's Stepper. The server sends the value, its
// bounds and its increment; the browser's number input does the stepping (stepUp and
// stepDown clamp to the bounds), so the view does no maths. Emits the new value once per
// tap; a side at its bound is off, so a tap always moves the value. Used by the
// training-day, daily-steps and meals steps.
const props = withDefaults(defineProps<{ label: string; text: string; modelValue: number; min: number; max: number; step: number; disabled?: boolean }>(), {
    disabled: false,
});

const emit = defineEmits<{ 'update:modelValue': [value: number] }>();

const input = ref<HTMLInputElement | null>(null);

function nudge(up: boolean): void {
    const field = input.value;
    if (!field) return;
    field.value = String(props.modelValue);
    if (up) field.stepUp();
    else field.stepDown();
    const value = field.valueAsNumber;
    if (Number.isFinite(value)) emit('update:modelValue', value);
}

const button = 'flex size-tap items-center justify-center text-xl font-semibold text-accent active:opacity-70 disabled:opacity-40';
</script>

<template>
    <div role="group" :aria-label="label" class="flex min-h-tap items-center justify-between gap-3">
        <span class="tabular-nums text-primary-text">{{ text }}</span>
        <input ref="input" type="number" tabindex="-1" aria-hidden="true" class="hidden" :min="min" :max="max" :step="step" :value="modelValue" />
        <div class="flex shrink-0 overflow-hidden rounded-lg bg-surface">
            <button type="button" :class="button" :aria-label="`Fewer: ${label}`" :disabled="disabled || modelValue <= min" @click="nudge(false)">−</button>
            <span class="my-2 w-px bg-secondary-text/25" aria-hidden="true" />
            <button type="button" :class="button" :aria-label="`More: ${label}`" :disabled="disabled || modelValue >= max" @click="nudge(true)">+</button>
        </div>
    </div>
</template>
