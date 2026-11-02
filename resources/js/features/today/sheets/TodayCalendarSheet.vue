<script setup lang="ts">
import { ref, watch } from 'vue';
import { useRead } from '@/app/useRead';
import type { WeekShowOutput } from '@/contracts/week_show';
import BottomSheet from '@/design/BottomSheet.vue';
import SheetBody from './SheetBody.vue';

// SCH-023: the calendar icon's month view, to jump to any date of the plan. It opens on
// the selected day and offers only the plan's dates (week_show.calendar). Go hands the
// date to the screen, which shows that day. Ported from rp-clone's TodayCalendarSheet.
const props = withDefaults(defineProps<{ payload?: WeekShowOutput; date?: string; open?: boolean }>(), { open: true });

const emit = defineEmits<{ pick: [date: string]; close: [saved: boolean] }>();

const date = props.date ?? props.payload?.days.find((day) => day.is_selected)?.date;
const { data, failure, load } = useRead<WeekShowOutput>('week_show', date ? { date } : {}, 'The calendar could not be loaded.', props.payload);

const picked = ref('');
watch(
    data,
    (week) => {
        if (week && !picked.value) picked.value = week.days.find((day) => day.is_selected)?.date ?? week.week_start;
    },
    { immediate: true },
);
</script>

<template>
    <BottomSheet
        :open="open"
        title="Jump to date"
        save-title="Go"
        :save-disabled="!data?.calendar || !picked"
        :heights="['half', 'full']"
        @cancel="emit('close', false)"
        @save="emit('pick', picked)"
    >
        <SheetBody :loaded="!!data" :failure="failure" what="The calendar" @retry="load">
            <label v-if="data?.calendar" class="flex min-h-tap items-center justify-between gap-3 rounded-2xl bg-surface px-4 py-2">
                <span class="text-primary-text">Date</span>
                <input
                    v-model="picked"
                    type="date"
                    :min="data.calendar.first"
                    :max="data.calendar.last"
                    class="min-w-36 rounded-lg bg-background px-3 py-1.5 tabular-nums text-primary-text accent-accent"
                />
            </label>
            <p v-else class="px-4 text-secondary-text">Your plan has no dates yet.</p>
        </SheetBody>
    </BottomSheet>
</template>
