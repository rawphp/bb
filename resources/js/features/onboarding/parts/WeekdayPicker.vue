<script setup lang="ts">
// Mon to Sun toggles (ISO weekdays), as the server lists them. Shared with Settings'
// schedule screens. Ported from rp-clone's OnboardingParts.
withDefaults(defineProps<{ days: { weekday: number; name: string; full_name: string; is_on: boolean }[]; disabled?: boolean }>(), { disabled: false });

const emit = defineEmits<{ toggle: [weekday: number] }>();
</script>

<template>
    <div class="flex gap-1">
        <button
            v-for="day in days"
            :key="day.weekday"
            type="button"
            :aria-label="day.full_name"
            :aria-pressed="day.is_on"
            :disabled="disabled"
            class="flex min-h-tap flex-1 items-center justify-center rounded-lg text-sm font-semibold disabled:opacity-50"
            :class="day.is_on ? 'bg-accent text-on-accent' : 'bg-background text-primary-text'"
            @click="emit('toggle', day.weekday)"
        >
            {{ day.name }}
        </button>
    </div>
</template>
