<script setup lang="ts">
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';
import type { DayOptimizeInput } from '@/contracts/day_optimize';
import type { DayOptimizeShowOutput } from '@/contracts/day_optimize_show';
import BottomSheet from '@/design/BottomSheet.vue';
import PendingMark from '@/design/PendingMark.vue';
import { useSheet, type SheetListeners } from './useSheet';

// SCH-036: Optimize schedule shows where the server would put each meal of the day
// before saving. The server places them (SCH-040 to SCH-046); the sheet shows each
// meal's time before and after.
const props = withDefaults(
    defineProps<
        {
            /** The day, from the screen or the route; a /dev/screens fixture carries its own. */
            date?: string;
            payload?: DayOptimizeShowOutput;
            open?: boolean;
            /** A change is waiting to sync, so the proposed times are not final (OFF-008). */
            pending?: boolean;
        } & SheetListeners
    >(),
    { open: true, pending: false },
);

const date = props.date ?? props.payload?.date ?? '';
const { data, failure, load } = useRead<DayOptimizeShowOutput>('day_optimize_show', { date }, 'The new schedule could not be loaded.', props.payload);
const { busy, error, close, save } = useSheet(props);

function submit(): void {
    if (!data.value) return;
    const input: DayOptimizeInput = { date: data.value.date };
    void save('day_optimize', { ...input }, 'The schedule could not be optimized. Try again.');
}
</script>

<template>
    <BottomSheet
        :open="open"
        title="Optimize schedule"
        :heights="['half']"
        :save-disabled="!data?.can_save || busy"
        :cancel-disabled="busy"
        :dismissible="!busy"
        :error="error"
        @cancel="close"
        @save="submit"
    >
        <div v-if="data" class="flex flex-col gap-3 p-4">
            <p v-if="data.message" class="text-secondary-text">{{ data.message }}</p>
            <div v-for="row in data.rows" :key="row.meal_id" class="flex min-h-tap items-center gap-2 tabular-nums" data-test="optimize-row">
                <span class="flex-1 text-primary-text">{{ row.name }}</span>
                <!-- Dimmed under a "Waiting to sync" mark while a change waits (OFF-008). -->
                <component :is="pending ? PendingMark : 'span'" :derived="pending || undefined">
                    <span class="flex items-center gap-2">
                        <span v-if="row.before_text == null" class="font-semibold text-accent">New · {{ row.after_text }}</span>
                        <span v-else-if="!row.changed" class="text-secondary-text">{{ row.after_text }}</span>
                        <span v-else class="flex items-center gap-2">
                            <span class="text-secondary-text">{{ row.before_text }}</span>
                            <svg aria-hidden="true" viewBox="0 0 24 24" class="size-3.5 text-secondary-text" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M4 12h15m-6-6 6 6-6 6" />
                            </svg>
                            <span class="sr-only">to</span>
                            <span class="font-semibold text-accent">{{ row.after_text ?? 'Removed' }}</span>
                        </span>
                    </span>
                </component>
            </div>
        </div>
        <div v-else class="p-4">
            <ReadState :failure="failure" what="The new schedule" @retry="load" />
        </div>
    </BottomSheet>
</template>
