<script setup lang="ts">
import { ref } from 'vue';
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';
import type { WeighInsShowOutput } from '@/contracts/weigh_ins_show';
import PendingMark from '@/design/PendingMark.vue';
import WeighInScheduleEntrySheet from './WeighInScheduleEntrySheet.vue';

// The weigh-in schedule (WT-001, WT-007), ported from rp-clone's WeighInScheduleView.swift:
// each entry's days and reminder time as the server words them, + to add one, and a tap
// to edit or delete one. It reads weigh_ins_show, which carries the schedule. `pending`
// marks the server's labels as waiting to sync (OFF-008).
const props = withDefaults(defineProps<{ payload?: WeighInsShowOutput; pending?: boolean }>(), { pending: false });

const { data, failure, load } = useRead<WeighInsShowOutput>('weigh_ins_show', {}, 'Your weigh-in schedule could not be loaded.', props.payload);

/** The entry sheet: the entry's place in the schedule, null for a new one; closed when undefined. */
const editing = ref<number | null | undefined>(undefined);
</script>

<template>
    <main class="bb-screen-content flex flex-col gap-4">
        <header class="flex items-center justify-between gap-2">
            <h1 class="text-3xl font-bold">Schedule</h1>
            <button
                type="button"
                aria-label="Add to schedule"
                :disabled="!data"
                class="flex min-h-tap min-w-tap items-center justify-center text-primary-text active:opacity-70 disabled:text-secondary-text"
                @click="editing = null"
            >
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <path d="M12 5v14M5 12h14" />
                </svg>
            </button>
        </header>

        <template v-if="data">
            <ul class="flex flex-col overflow-hidden rounded-2xl bg-surface">
                <li v-for="(entry, index) in data.schedule.entries" :key="index" class="border-secondary-text/15 [&+&]:border-t">
                    <button
                        type="button"
                        data-test="entry"
                        class="flex min-h-tap w-full items-center gap-3 px-4 py-2.5 text-left active:opacity-70"
                        :aria-label="`Edit ${entry.days_label} at ${entry.time_label}`"
                        @click="editing = index"
                    >
                        <span class="flex flex-1 flex-col">
                            <span class="text-primary-text">
                                <PendingMark v-if="pending" derived>{{ entry.days_label }}</PendingMark>
                                <template v-else>{{ entry.days_label }}</template>
                            </span>
                            <span class="text-sm tabular-nums text-secondary-text">{{ entry.time_label }}</span>
                        </span>
                        <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4 text-secondary-text" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m9 6 6 6-6 6" />
                        </svg>
                    </button>
                </li>
            </ul>
            <p class="px-1 text-sm text-secondary-text">{{ data.schedule.note }}</p>

            <WeighInScheduleEntrySheet
                :open="editing !== undefined"
                :schedule="data.schedule"
                :index="editing ?? null"
                :reload="load"
                @cancel="editing = undefined"
                @done="editing = undefined"
            />
        </template>
        <ReadState v-else :failure="failure" what="Your weigh-in schedule" @retry="load" />
    </main>
</template>
