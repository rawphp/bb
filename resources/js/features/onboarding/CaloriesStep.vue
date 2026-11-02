<script setup lang="ts">
import { computed } from 'vue';
import type { OnboardingShowOutput } from '@/contracts/onboarding_show';
import ChoiceList from '@/design/ChoiceList.vue';
import PendingMark from '@/design/PendingMark.vue';
import { picked, toChoices, type Answers } from './choices';
import NumberField from './parts/NumberField.vue';
import StepHeading from './parts/StepHeading.vue';
import StepNote from './parts/StepNote.vue';
import StepPanel from './parts/StepPanel.vue';

type Section = OnboardingShowOutput['calories'];
type Pick = NonNullable<Answers['calories']>;

// Step 13: the recommendation or the user's own daily average (ONB-017), and the week
// the server works out from it. The daily average is saved when the field is left. While
// a change waits to sync, the recommendation's average and the week are dimmed and marked
// (OFF-008). Ported from rp-clone's LifestyleSteps.
const props = defineProps<{ calories: Section; locked?: boolean; pending?: boolean }>();

const emit = defineEmits<{ answer: [answers: Answers] }>();

const choice = computed(() => picked<Pick>(props.calories.options));

// Whole kcal, as the answer takes them.
function typedKcal(kcal: number | null): void {
    if (kcal !== null) emit('answer', { own_kcal: Math.round(kcal) });
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <ChoiceList
            label="Calories"
            :class="{ 'opacity-60': pending }"
            :choices="toChoices<Pick>(calories.options)"
            :model-value="choice"
            :disabled="locked"
            @update:model-value="(value) => emit('answer', { calories: value })"
        />
        <NumberField
            v-if="choice === 'own'"
            label="Daily average"
            :unit="calories.own_unit"
            :model-value="calories.own_kcal"
            :commits-while-typing="false"
            :readonly="locked"
            @update:model-value="typedKcal"
        />
        <template v-if="calories.week.length > 0">
            <StepHeading text="Your week" />
            <StepNote :text="calories.week_note" />
            <PendingMark v-if="pending" />
            <StepPanel>
                <div v-for="day in calories.week" :key="day.name" class="flex min-h-8 items-center justify-between">
                    <span class="text-primary-text">{{ day.name }}</span>
                    <span class="tabular-nums text-primary-text" :class="{ 'opacity-60': pending }">{{ day.kcal_text }}</span>
                </div>
            </StepPanel>
        </template>
    </div>
</template>
