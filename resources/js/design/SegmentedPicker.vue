<script setup lang="ts" generic="T extends string">
// SwiftUI's segmented Picker: one row of mutually exclusive options, the chosen one raised.
// An undefined model means nothing is chosen yet. Picking the chosen option sends nothing.
const props = withDefaults(defineProps<{ label: string; options: { value: T; label: string; disabled?: boolean }[]; modelValue?: T; disabled?: boolean }>(), {
    modelValue: undefined,
    disabled: false,
});

const emit = defineEmits<{ 'update:modelValue': [value: T] }>();

function pick(value: T): void {
    if (value !== props.modelValue) emit('update:modelValue', value);
}
</script>

<template>
    <div role="radiogroup" :aria-label="label" class="flex rounded-lg bg-background p-0.5">
        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            role="radio"
            :aria-checked="modelValue === option.value"
            :disabled="disabled || option.disabled"
            class="min-h-9 flex-1 rounded-md px-2 text-sm font-medium text-primary-text disabled:opacity-50"
            :class="modelValue === option.value ? 'bg-surface shadow-sm' : ''"
            @click="pick(option.value)"
        >
            {{ option.label }}
        </button>
    </div>
</template>
