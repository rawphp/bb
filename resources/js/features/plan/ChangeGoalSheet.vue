<script setup lang="ts">
import { computed, ref } from 'vue';
import type { PhaseShowOutput } from '@/contracts/phase_show';
import BottomSheet from '@/design/BottomSheet.vue';
import ChoiceList, { type Choice } from '@/design/ChoiceList.vue';
import { toneClasses } from '@/design/tones';

type Goal = PhaseShowOutput['goals'][number];

// PH-009: each other goal phase_show offers, previewed: when it starts, its length and
// rate, how the running mesocycle's progression follows it, and the training the next
// mesocycle starts from. Warnings show and never block (PH-010 to PH-012); a refusal
// blocks Change. Ported from rp-clone's ChangeGoalSheet.
const props = defineProps<{ goals: Goal[]; busy: boolean; error: string | null }>();

const emit = defineEmits<{ close: []; change: [type: Goal['type']] }>();

const picked = ref<Goal['type'] | null>(null);
const goal = computed(() => props.goals.find((option) => option.type === picked.value) ?? null);
const choices = computed<Choice<Goal['type']>[]>(() => props.goals.map((option) => ({ id: option.type, title: option.title })));
</script>

<template>
    <BottomSheet
        :open="true"
        title="Change goal"
        save-title="Change"
        :save-disabled="!goal || !!goal.refusal || busy"
        :cancel-disabled="busy"
        :dismissible="!busy"
        @cancel="emit('close')"
        @save="goal && emit('change', goal.type)"
    >
        <div class="space-y-4 p-4" data-test="goal-sheet">
            <section>
                <h2 class="px-1 pb-1.5 text-sm font-semibold text-secondary-text">New goal</h2>
                <ChoiceList v-model="picked" label="New goal" :choices="choices" :disabled="busy" />
            </section>

            <div v-if="goal" class="space-y-4" data-test="goal-preview">
                <div class="space-y-1 rounded-2xl bg-surface px-4 py-3">
                    <p>{{ goal.from_text }}</p>
                    <p>{{ goal.length_text }}</p>
                    <p v-if="goal.rate_text">{{ goal.rate_text }}</p>
                    <p v-if="goal.progression_text" class="text-[13px] text-secondary-text">{{ goal.progression_text }}</p>
                </div>
                <p v-if="goal.refusal" role="alert" class="rounded-xl p-3 text-[13px]" :class="toneClasses.warning">{{ goal.refusal }}</p>
                <p v-for="warning in goal.warnings" :key="warning.text" class="rounded-xl p-3 text-[13px]" :class="toneClasses[warning.tone]">{{ warning.text }}</p>
                <section v-if="goal.training_lines.length">
                    <h2 class="px-1 pb-1.5 text-sm font-semibold text-secondary-text">The next mesocycle starts from</h2>
                    <ul class="divide-y divide-secondary-text/15 rounded-2xl bg-surface">
                        <li v-for="line in goal.training_lines" :key="line.text" class="px-4 py-2.5 text-[13px]" data-test="training-line">{{ line.text }}</li>
                    </ul>
                </section>
            </div>

            <p v-if="error" role="alert" class="rounded-xl p-3 text-sm" :class="toneClasses.warning">{{ error }}</p>
        </div>
    </BottomSheet>
</template>
