<script setup lang="ts">
// One chip per weekday, Monday first, as the server lists them; a chosen day is filled.
// Ported from rp-clone's WeekdayChips.
withDefaults(defineProps<{ days: { iso: number; label: string }[]; selected: number[]; disabled?: boolean }>(), { disabled: false });

const emit = defineEmits<{ toggle: [iso: number] }>();
</script>

<template>
    <div class="flex gap-1.5 py-2">
        <button
            v-for="day in days"
            :key="day.iso"
            type="button"
            :aria-label="day.label"
            :aria-pressed="selected.includes(day.iso)"
            :disabled="disabled"
            class="flex min-h-tap flex-1 items-center justify-center rounded-full text-sm font-semibold disabled:opacity-50"
            :class="selected.includes(day.iso) ? 'bg-accent text-on-accent' : 'text-primary-text'"
            @click="emit('toggle', day.iso)"
        >
            {{ day.label.charAt(0) }}
        </button>
    </div>
</template>
