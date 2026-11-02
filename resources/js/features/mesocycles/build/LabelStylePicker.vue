<script setup lang="ts">
import { computed } from 'vue';
import ChoiceList, { type Choice } from '@/design/ChoiceList.vue';
import type { Build, LabelStyle } from './types';

// How the sessions are named (MESO-005): weekday names, or Day 1, Day 2. Whether weekday
// names are open to the user comes from the server. Used on the details step.
const props = defineProps<{ build: Build; disabled?: boolean }>();

const emit = defineEmits<{ change: [style: LabelStyle] }>();

const choices = computed<Choice<LabelStyle>[]>(() => [
    {
        id: 'weekdays',
        title: 'Weekdays',
        isEnabled: props.build.weekdays_available,
        detail: props.build.weekdays_available ? undefined : 'Set your training days to use weekday names.',
    },
    { id: 'floating', title: 'Day 1, Day 2' },
]);
</script>

<template>
    <ChoiceList label="Day labels" :choices="choices" :model-value="build.label_style" :disabled="disabled" @update:model-value="emit('change', $event)" />
</template>
