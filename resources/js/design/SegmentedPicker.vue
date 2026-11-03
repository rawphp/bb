<script setup lang="ts" generic="T extends string">
// SwiftUI's segmented Picker: one row of mutually exclusive options, the chosen one raised.
withDefaults(defineProps<{ label: string; options: { value: T; label: string }[]; disabled?: boolean }>(), { disabled: false });

const picked = defineModel<T>({ required: true });
</script>

<template>
    <div role="radiogroup" :aria-label="label" class="flex rounded-lg bg-background p-0.5">
        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            role="radio"
            :aria-checked="picked === option.value"
            :disabled="disabled"
            class="min-h-9 flex-1 rounded-md px-2 text-sm font-medium text-primary-text disabled:opacity-50"
            :class="picked === option.value ? 'bg-surface shadow-sm' : ''"
            @click="picked = option.value"
        >
            {{ option.label }}
        </button>
    </div>
</template>
