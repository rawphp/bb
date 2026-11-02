<script setup lang="ts">
// A labelled time of day, held as the server takes it (24-hour "HH:MM") and shown in the
// browser's own time format.
withDefaults(defineProps<{ label: string; disabled?: boolean }>(), { disabled: false });

const time = defineModel<string>({ required: true });
</script>

<template>
    <label class="flex min-h-tap items-center justify-between gap-3 py-1">
        <span class="text-primary-text">{{ label }}</span>
        <input
            v-model="time"
            type="time"
            :disabled="disabled"
            class="min-w-36 rounded-lg bg-background px-3 py-1.5 tabular-nums text-primary-text accent-accent disabled:opacity-50"
        />
    </label>
</template>
