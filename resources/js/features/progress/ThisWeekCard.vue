<script setup lang="ts">
import { computed } from 'vue';
import type { ProgressShowOutput } from '@/contracts/progress_show';
import ItemCard from '@/design/ItemCard.vue';
import ProgressBar from '@/design/ProgressBar.vue';
import WeekStrip, { type WeekStripDay } from '@/design/WeekStrip.vue';

// Calories against the week's target, the week strip and Edit schedule (ANA-003, ANA-011).
const props = defineProps<{ week: NonNullable<ProgressShowOutput['this_week']>; pending?: boolean }>();
const emit = defineEmits<{ 'edit-schedule': [] }>();

// The strip's fields, renamed from progress_show's days. Today is the selected day, as in rp-clone.
const days = computed<WeekStripDay[]>(() =>
    props.week.days.map((day) => ({
        date: day.date,
        letter: day.letter,
        number: day.day_label,
        calories_text: day.calories_label ?? '–',
        is_today: day.is_today,
        is_selected: day.is_today,
        is_complete: day.is_complete,
        has_training: false,
        is_before_plan: false,
    })),
);
</script>

<template>
    <ItemCard data-this-week :title="week.title" :pending="pending">
        <template #icon>
            <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round">
                <rect x="3.5" y="5" width="17" height="15" rx="2.5" />
                <path d="M3.5 9.5h17M8 3v4M16 3v4M8 13h.01M12 13h.01M16 13h.01M8 16.5h.01M12 16.5h.01" />
            </svg>
        </template>
        <div class="flex flex-col gap-3">
            <ProgressBar
                v-if="week.calories"
                :title="week.calories.title"
                :label="week.calories.short_label"
                :value-text="week.calories.value_label"
                :fraction="week.calories.fraction"
            />
            <WeekStrip :days="days" :label="week.title" class="-mx-2" />
            <button data-edit-schedule type="button" class="min-h-tap self-start text-accent" @click="emit('edit-schedule')">
                {{ week.edit_schedule_title }}
            </button>
        </div>
    </ItemCard>
</template>
