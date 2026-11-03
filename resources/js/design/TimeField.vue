<script setup lang="ts">
// A labelled time of day, held as the server takes it (24-hour "HH:MM") and shown in the
// browser's own time format. The model changes once a time is picked (the input's change
// event), not on every keystroke, so a caller that saves each pick saves one answer. The
// caller's classes go on the row; every other attribute goes on the input.
defineOptions({ inheritAttrs: false });
withDefaults(defineProps<{ label: string; disabled?: boolean }>(), { disabled: false });

const time = defineModel<string>({ required: true });
</script>

<template>
    <label class="flex min-h-tap items-center justify-between gap-3 py-1" :class="$attrs.class">
        <span class="text-primary-text">{{ label }}</span>
        <input
            v-bind="{ ...$attrs, class: undefined }"
            type="time"
            :value="time"
            :disabled="disabled"
            class="min-w-36 rounded-lg bg-background px-3 py-1.5 tabular-nums text-primary-text accent-accent disabled:opacity-50"
            @change="time = ($event.target as HTMLInputElement).value"
        />
    </label>
</template>
