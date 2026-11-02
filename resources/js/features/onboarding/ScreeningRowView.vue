<script setup lang="ts">
import type { OnboardingShowOutput } from '@/contracts/onboarding_show';

// One screening item as a tick box. An item step 2 answers (age, height, weight, BMI)
// is locked, with a lock icon. Ported from rp-clone's ScreeningStep.
defineProps<{ row: OnboardingShowOutput['screening']['rows'][number]; disabled?: boolean }>();

const emit = defineEmits<{ toggle: [] }>();
</script>

<template>
    <button
        type="button"
        role="checkbox"
        :aria-checked="row.is_on"
        :disabled="row.is_locked || disabled"
        class="flex min-h-tap w-full items-center gap-3 rounded-xl bg-background px-3.5 py-1.5 text-left active:opacity-70"
        :class="{ 'opacity-60': disabled && !row.is_locked }"
        @click="emit('toggle')"
    >
        <svg viewBox="0 0 24 24" class="size-6 shrink-0" :class="row.is_on ? 'text-accent' : 'text-secondary-text'" aria-hidden="true">
            <template v-if="row.is_on">
                <rect x="3" y="3" width="18" height="18" rx="4" fill="currentColor" />
                <path d="M7.5 12.5l3 3 6-6.5" fill="none" class="stroke-on-accent" stroke-width="2.25" />
            </template>
            <rect v-else x="3.75" y="3.75" width="16.5" height="16.5" rx="3.5" fill="none" stroke="currentColor" stroke-width="1.75" />
        </svg>
        <span class="flex min-w-0 grow flex-col gap-0.5">
            <span class="text-primary-text">{{ row.label }}</span>
            <span v-if="row.detail" class="text-sm text-secondary-text">{{ row.detail }}</span>
        </span>
        <svg v-if="row.is_locked" data-test="locked" viewBox="0 0 24 24" class="size-5 shrink-0 text-secondary-text" fill="currentColor" aria-hidden="true">
            <path d="M12 2a5 5 0 0 0-5 5v3H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2h-1V7a5 5 0 0 0-5-5zm-3 8V7a3 3 0 1 1 6 0v3z" />
        </svg>
    </button>
</template>
