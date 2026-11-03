<script setup lang="ts">
import { computed } from 'vue';
import TimeField from '@/design/TimeField.vue';

// A time of day held as minutes after midnight, shown by the design TimeField. For the
// training-day and weigh-in steps; Settings reuses it (19-views). A cleared time is
// ignored. Ported from rp-clone's OnboardingParts.
const props = withDefaults(defineProps<{ label: string; modelValue: number; disabled?: boolean }>(), { disabled: false });

const emit = defineEmits<{ 'update:modelValue': [minutes: number] }>();

const two = (n: number) => String(n).padStart(2, '0');
const value = computed(() => `${two(Math.floor(props.modelValue / 60))}:${two(props.modelValue % 60)}`);

function picked(time: string): void {
    const [hours, minutes] = time.split(':').map(Number);
    if (hours === undefined || minutes === undefined || Number.isNaN(hours) || Number.isNaN(minutes)) return;
    emit('update:modelValue', hours * 60 + minutes);
}
</script>

<template>
    <TimeField :label="label" :model-value="value" :disabled="disabled" @update:model-value="picked" />
</template>
