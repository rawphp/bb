<script setup lang="ts">
import { ref } from 'vue';
import ReadState from '@/app/ReadState.vue';
import type { DayRedistributeShowInput, DayRedistributeShowOutput } from '@/contracts/day_redistribute_show';
import type { MealRedistributeInput } from '@/contracts/meal_redistribute';
import BottomSheet from '@/design/BottomSheet.vue';
import RedistributeRowView from './RedistributeRowView.vue';
import WarningLine from './WarningLine.vue';
import { useSheet, useSheetRead, type SheetListeners } from './useSheet';

// DAY-002: which later meals take what is left of the day, each with the calories and
// protein it would hold. The server decides which meals can, and what each would hold;
// the sheet keeps only the meals the user has left out.
const props = withDefaults(
    defineProps<
        {
            /** The day, from the screen or the route; a /dev/screens fixture carries its own. */
            date?: string;
            payload?: DayRedistributeShowOutput;
            open?: boolean;
            /** A change is waiting to sync, so what each meal would hold is not final (OFF-008). */
            pending?: boolean;
        } & SheetListeners
    >(),
    { open: true, pending: false },
);

const date = props.date ?? props.payload?.date ?? '';
// The meals the user has left out; null until the first tap, so the first read is the server's own pick.
const excluded = ref<string[] | null>(null);
const { data, failure, load } = useSheetRead<DayRedistributeShowOutput>(
    'day_redistribute_show',
    () => {
        const input: DayRedistributeShowInput = excluded.value === null ? { date } : { date, excluded_meal_ids: excluded.value };
        return { ...input };
    },
    'The meals to redistribute over could not be loaded.',
    props.payload,
);
const { busy, error, close, save } = useSheet(props);

function toggle(row: DayRedistributeShowOutput['rows'][number]): void {
    const ids = excluded.value ?? [];
    excluded.value = row.selected ? [...ids, row.id] : ids.filter((id) => id !== row.id);
    void load();
}

function confirm(): void {
    if (!data.value) return;
    const input: MealRedistributeInput = { date: data.value.date, meal_ids: data.value.rows.filter((row) => row.selected).map((row) => row.id) };
    void save('meal_redistribute', { ...input }, 'The meals could not be redistributed. Try again.');
}
</script>

<template>
    <BottomSheet
        :open="open"
        title="Redistribute"
        save-title="Confirm"
        :heights="['half', 'full']"
        :save-disabled="!data?.can_confirm || busy"
        :cancel-disabled="busy"
        :dismissible="!busy"
        :error="error"
        @cancel="close"
        @save="confirm"
    >
        <div class="flex flex-col gap-3.5 p-4">
            <p class="text-sm text-secondary-text">Choose the meals that take the rest of the day's calories and macros.</p>
            <template v-if="data">
                <RedistributeRowView v-for="row in data.rows" :key="row.id" :row="row" :pending="pending" @toggle="toggle(row)" />
                <WarningLine v-for="warning in data.warnings" :key="warning.text" :text="warning.text" />
                <p class="text-xs text-secondary-text">{{ data.note }}</p>
            </template>
            <ReadState v-else :failure="failure" what="The meals to redistribute over" @retry="load" />
        </div>
    </BottomSheet>
</template>
