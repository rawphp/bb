<script setup lang="ts">
import type { OnboardingShowOutput } from '@/contracts/onboarding_show';
import PendingMark from '@/design/PendingMark.vue';
import { toneClasses } from '@/design/tones';
import OnboardingCallout from './parts/OnboardingCallout.vue';
import StepPanel from './parts/StepPanel.vue';

// The review before "Start my plan" (ONB-018, ONB-019): every section of the plan as the
// server summarises it, each with an Edit link back to its step, and the fine-tuning
// note. While a change waits to sync the summary is marked (OFF-008). Ported from
// rp-clone's ReviewStep.
defineProps<{ review: OnboardingShowOutput['review']; locked?: boolean; pending?: boolean }>();

const emit = defineEmits<{ edit: [step: number] }>();
</script>

<template>
    <div class="flex flex-col gap-4">
        <PendingMark v-if="pending" />
        <StepPanel v-for="section in review.sections" :key="section.title">
            <div class="flex items-center justify-between gap-2">
                <h3 class="text-base font-semibold text-primary-text">{{ section.title }}</h3>
                <button
                    v-if="section.can_edit"
                    type="button"
                    :aria-label="`Edit ${section.title}`"
                    :disabled="locked"
                    class="min-h-tap min-w-tap font-medium text-accent active:opacity-70 disabled:opacity-50"
                    @click="emit('edit', section.edit_step)"
                >
                    Edit
                </button>
            </div>
            <div v-for="row in section.rows" :key="row.label" class="flex items-baseline justify-between gap-2">
                <span class="text-sm text-secondary-text">{{ row.label }}</span>
                <span class="flex flex-col items-end gap-1" :class="{ 'opacity-60': pending }">
                    <span class="text-right text-sm text-primary-text">{{ row.value }}</span>
                    <span v-if="row.badges.length > 0" class="flex flex-wrap justify-end gap-1">
                        <span v-for="badge in row.badges" :key="badge.text" class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="toneClasses[badge.tone]">
                            {{ badge.text }}
                        </span>
                    </span>
                </span>
            </div>
        </StepPanel>
        <OnboardingCallout v-if="review.note" :text="review.note" />
    </div>
</template>
