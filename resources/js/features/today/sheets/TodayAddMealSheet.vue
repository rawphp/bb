<script setup lang="ts">
import { ref } from 'vue';
import { ulid } from '@/app/ulid';
import type { MealAddInput } from '@/contracts/meal_add';
import BottomSheet from '@/design/BottomSheet.vue';
import SheetBody from './SheetBody.vue';
import TimeField from './TimeField.vue';
import { useDaySheet, type DaySheetProps } from './useDaySheet';

// SCH-031: the schedule menu's Add meal picks the time of an extra meal. The server shares
// the day's macros across the meals not yet checked in. Ported from rp-clone's TodayAddMealSheet.
const props = withDefaults(defineProps<DaySheetProps & { open?: boolean }>(), { open: true });

const emit = defineEmits<{ close: [saved: boolean] }>();

const { data, failure, load, send, busy, error, seed } = useDaySheet(props);

// One id for the meal this sheet adds, so a retry after no answer is the same create (CAP-019).
const id = ulid();
const time = ref('');
seed((day) => (time.value = day.add_meal_time ?? ''));

async function save(): Promise<void> {
    if (!data.value || !time.value) return;
    const input = { id, date: data.value.date, time: time.value } satisfies MealAddInput;
    if ((await send('meal_add', input, 'The meal could not be added.')) === 'done') emit('close', true);
}
</script>

<template>
    <BottomSheet
        :open="open"
        title="Add meal"
        :save-disabled="!data?.add_meal_time || !time || busy"
        :cancel-disabled="busy"
        :error="error"
        :pending="pending"
        :heights="['half']"
        @cancel="emit('close', false)"
        @save="save"
    >
        <SheetBody :loaded="!!data" :failure="failure" what="This day" @retry="load">
            <template v-if="data">
                <p class="text-center text-sm text-secondary-text">{{ data.title }}</p>
                <template v-if="data.add_meal_time">
                    <div class="rounded-2xl bg-surface px-4">
                        <TimeField v-model="time" label="Time" :disabled="busy" />
                    </div>
                    <p class="text-center text-sm text-secondary-text">The day's macros are shared across the meals you haven't checked in.</p>
                </template>
                <p v-else class="text-center text-secondary-text">A meal can't be added to a past day.</p>
            </template>
        </SheetBody>
    </BottomSheet>
</template>
