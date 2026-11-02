<script setup lang="ts">
import type { OnboardingShowOutput } from '@/contracts/onboarding_show';
import type { Answers } from './choices';
import OnboardingCallout from './parts/OnboardingCallout.vue';
import StepHeading from './parts/StepHeading.vue';
import ScreeningRowView from './ScreeningRowView.vue';

type Item = NonNullable<Answers['screening']>[number]['item'];
type Row = OnboardingShowOutput['screening']['rows'][number];

// Step 3: the health screening (ONB-005, SAFE-001), between the medical clearance
// (SAFE-002) and medical diets (SAFE-014) notes. Each tick is saved as it is made; the
// server decides what it rules out. Ported from rp-clone's ScreeningStep.
defineProps<{ screening: OnboardingShowOutput['screening'] }>();

const emit = defineEmits<{ answer: [answers: Answers] }>();

// A locked row is disabled, so only the items a person ticks are ever sent.
function toggle(row: Row): void {
    emit('answer', { screening: [{ item: row.item as Item, is_on: !row.is_on }] });
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <OnboardingCallout :text="screening.clearance_note.text" :tone="screening.clearance_note.tone" />
        <StepHeading :text="screening.prompt" />
        <div class="flex flex-col gap-2">
            <ScreeningRowView v-for="row in screening.rows" :key="row.item" :row="row" @toggle="toggle(row)" />
        </div>
        <OnboardingCallout :text="screening.medical_diets_note.text" :tone="screening.medical_diets_note.tone" />
    </div>
</template>
