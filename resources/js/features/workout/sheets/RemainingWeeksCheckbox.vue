<script setup lang="ts">
// "Apply to all remaining weeks" (MESO-045): off, a change is for this session only; on, it
// reaches every week of the mesocycle not yet done. Ported from rp-clone's RemainingWeeksCheckbox.
const model = defineModel<boolean>({ default: false });
</script>

<template>
    <button type="button" role="checkbox" :aria-checked="model" class="flex min-h-tap w-full items-center gap-3 py-1 text-left" @click="model = !model">
        <svg viewBox="0 0 24 24" class="size-7 shrink-0" :class="model ? 'text-accent' : 'text-secondary-text'" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
            <template v-if="model">
                <rect x="3.5" y="3.5" width="17" height="17" rx="3.5" fill="currentColor" />
                <path d="m7.5 12 3 3 6-6" stroke="var(--color-on-accent)" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" />
            </template>
            <rect v-else x="3.5" y="3.5" width="17" height="17" rx="3.5" />
        </svg>
        <span class="flex flex-col">
            <span class="text-primary-text">Apply to all remaining weeks</span>
            <span class="text-sm text-secondary-text">{{ model ? 'This day changes in every week not yet done.' : 'Off: this session only.' }}</span>
        </span>
    </button>
</template>
