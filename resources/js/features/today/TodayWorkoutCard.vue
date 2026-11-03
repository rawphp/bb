<script setup lang="ts">
import { computed } from 'vue';
import type { TodayShowOutput } from '@/contracts/today_show';
import ItemCard, { type ItemCardStatus } from '@/design/ItemCard.vue';
import { toneText } from '@/design/toneText';

// SCH-003: a workout with its Start button, or Done and the sets completed; SCH-053:
// Skipped, with no Start. A long press opens its menu (SCH-050, SCH-051). Ported from
// rp-clone's TodayWorkoutCard.
const props = withDefaults(defineProps<{ workout: TodayShowOutput['workouts'][number]; condensed: boolean; pending?: boolean }>(), { pending: false });

const emit = defineEmits<{ start: []; menu: [] }>();

const status = computed<ItemCardStatus>(() =>
    props.workout.status_text ? { kind: 'value', text: props.workout.status_text } : { kind: 'button', title: 'Start' },
);
</script>

<template>
    <div data-test="workout-card" @contextmenu.prevent="emit('menu')">
        <ItemCard
            :title="condensed ? workout.name : workout.title"
            :time="condensed ? workout.condensed_time_text : workout.time_text"
            :status="status"
            :pending="pending"
            @action="emit('start')"
        >
            <template #icon>
                <svg viewBox="0 0 24 24" class="size-6" fill="currentColor" aria-hidden="true">
                    <path d="M1 10h2V7h3v10H3v-3H1zm22 0h-2V7h-3v10h3v-3h2zM7 5h3v14H7zm7 0h3v14h-3zm-4 6h4v2h-4z" />
                </svg>
            </template>
            <div v-if="!condensed" class="flex flex-col gap-1.5">
                <p class="text-sm font-medium">{{ workout.summary_text }}</p>
                <p v-for="(exercise, index) in workout.exercises" :key="index" class="flex justify-between gap-2 text-sm text-secondary-text">
                    <span>{{ exercise.name }}</span>
                    <span class="tabular-nums">{{ exercise.sets_text }}</span>
                </p>
                <p v-if="workout.completed" class="text-sm font-semibold" :class="toneText[workout.completed.tone]">{{ workout.completed.text }}</p>
            </div>
        </ItemCard>
    </div>
</template>
