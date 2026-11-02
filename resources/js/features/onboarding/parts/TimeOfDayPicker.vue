<script setup lang="ts">
import { computed } from 'vue';

// A time of day held as minutes after midnight, shown in the browser's own time format.
// Shared with Settings' schedule screens. Ported from rp-clone's OnboardingParts.
const props = withDefaults(defineProps<{ label: string; modelValue: number; disabled?: boolean }>(), { disabled: false });

const emit = defineEmits<{ 'update:modelValue': [minutes: number] }>();

const two = (n: number) => String(n).padStart(2, '0');
const value = computed(() => `${two(Math.floor(props.modelValue / 60))}:${two(props.modelValue % 60)}`);

function picked(event: Event): void {
    const [hours, minutes] = (event.target as HTMLInputElement).value.split(':').map(Number);
    if (hours === undefined || minutes === undefined || Number.isNaN(hours) || Number.isNaN(minutes)) return;
    emit('update:modelValue', hours * 60 + minutes);
}
</script>

<template>
    <label class="flex min-h-tap items-center justify-between gap-3">
        <span class="text-primary-text">{{ label }}</span>
        <input
            type="time"
            :value="value"
            :disabled="disabled"
            class="rounded-lg bg-background px-3 py-1.5 tabular-nums text-primary-text accent-accent disabled:opacity-50"
            @change="picked"
        />
    </label>
</template>
