<script setup lang="ts">
import { computed } from 'vue';
import type { ReviewShowOutput } from '@/contracts/review_show';
import ChoiceList, { type Choice } from '@/design/ChoiceList.vue';
import ReviewNote from './ReviewNote.vue';

type Goal = NonNullable<ReviewShowOutput['goal']>;

// Step 5 (REV-003, PH-041): keep the goal, the server's pick, or choose a new one or end
// the plan, which open the phase transition (PH-030). Picking one of those only opens it.
const props = defineProps<{ goal: Goal }>();
const emit = defineEmits<{ transition: [] }>();

const choices = computed<Choice<string>[]>(() =>
    props.goal.options.map((o) => ({ id: o.id, title: o.title, detail: o.detail || undefined, isRecommended: o.is_recommended, isEnabled: o.is_enabled })),
);
const selected = computed(() => props.goal.options.find((o) => o.is_selected)?.id ?? null);
</script>

<template>
    <div class="flex flex-col gap-3" data-test="step-goal">
        <ReviewNote v-for="note in goal.notes" :key="note.text" v-bind="note" />
        <ChoiceList label="Goal" :choices="choices" :model-value="selected" data-test="goal-options" @update:model-value="emit('transition')" />
    </div>
</template>
