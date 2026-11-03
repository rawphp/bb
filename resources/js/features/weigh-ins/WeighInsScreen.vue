<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { findNavigation } from '@/app/navigation';
import type { WeighInsShowOutput } from '@/contracts/weigh_ins_show';
import HelpButton from '@/design/HelpButton.vue';
import ItemCard from '@/design/ItemCard.vue';
import PendingMark from '@/design/PendingMark.vue';
import StatusPill from '@/design/StatusPill.vue';
import { toneText } from '@/design/toneText';
import StepsEntrySheet from './StepsEntrySheet.vue';
import WeighInEntrySheet from './WeighInEntrySheet.vue';

// The Weigh-ins list (WT-004 to WT-006, WT-020), ported from rp-clone's WeighInsScreen:
// the trend weight, the schedule, every weigh-in newest first with the missed days the
// server marks, and the step counts. A weigh-in opens to edit or delete; a missed day
// opens a new one at the time the server gives for it. Display state only: which sheet
// is open. `reload` is the screen's read, run after each write.
type Row = WeighInsShowOutput['rows'][number];
type StepRow = WeighInsShowOutput['steps']['rows'][number];

const props = withDefaults(
    defineProps<{
        data: WeighInsShowOutput;
        reload: (options?: { fresh?: boolean }) => Promise<void>;
        pending?: boolean;
        /** Opened by /weigh-ins/log: a new weigh-in at this time, or now when empty. */
        log?: string;
    }>(),
    { pending: false, log: undefined },
);

const router = useRouter();
const nav = findNavigation(router);

/** The weigh-in sheet: the row to edit, or a new one starting at `at` (now when null). */
const weighIn = ref<{ row: Row | null; at: string | null } | null>(props.log === undefined ? null : { row: null, at: props.log || null });
/** The open sheet is the one /weigh-ins/log asked for, so closing it goes back. */
const fromRoute = ref(props.log !== undefined);
/** The steps sheet: the day to edit, or a new count. */
const steps = ref<{ row: StepRow | null } | null>(null);

function openRow(row: Row): void {
    weighIn.value = row.is_missed ? { row: null, at: row.weighed_at } : { row, at: null };
}

function closeWeighIn(): void {
    weighIn.value = null;
    if (!fromRoute.value) return;
    fromRoute.value = false;
    // Back down the stack it was opened on; opened as the first screen, stay on Weigh-ins.
    if (nav && nav.state.stacks[nav.state.active].length > 1) nav.back();
    else void router.replace('/weigh-ins');
}
</script>

<template>
    <main class="bb-screen-content flex flex-col gap-4">
        <header class="flex items-center justify-between gap-2">
            <h1 class="text-3xl font-bold">{{ data.title }}</h1>
            <div class="flex items-center">
                <HelpButton :title="data.help.title" :message="data.help.message" />
                <button
                    type="button"
                    aria-label="Log weigh-in"
                    class="flex min-h-tap min-w-tap items-center justify-center text-primary-text active:opacity-70"
                    @click="weighIn = { row: null, at: null }"
                >
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <path d="M12 5v14M5 12h14" />
                    </svg>
                </button>
            </div>
        </header>

        <section data-test="trend" class="rounded-2xl bg-surface p-4">
            <p class="text-sm text-secondary-text">{{ data.trend.label }}</p>
            <p class="text-4xl font-bold tabular-nums" :class="toneText[data.trend.tone]">
                <PendingMark v-if="pending" derived>{{ data.trend.value }}</PendingMark>
                <template v-else>{{ data.trend.value }}</template>
            </p>
        </section>

        <button
            type="button"
            data-test="schedule"
            class="flex min-h-tap items-center gap-3 rounded-2xl bg-surface px-4 py-3 text-left text-primary-text active:opacity-70"
            @click="router.push('/weigh-ins/schedule')"
        >
            <svg aria-hidden="true" viewBox="0 0 24 24" class="size-6 shrink-0 text-accent" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round">
                <rect x="3.5" y="5" width="17" height="15" rx="2.5" />
                <path d="M3.5 9.5h17M8 3v4M16 3v4" stroke-linecap="round" />
            </svg>
            <span class="flex-1">{{ data.schedule.summary }}</span>
            <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4 text-secondary-text" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="m9 6 6 6-6 6" />
            </svg>
        </button>

        <ul v-if="data.rows.length" class="flex flex-col gap-2">
            <li v-for="row in data.rows" :key="row.id">
                <button
                    type="button"
                    data-test="weigh-in"
                    class="block w-full text-left active:opacity-70"
                    :aria-label="row.is_missed ? `Log the missed weigh-in for ${row.date_label}` : `Edit the weigh-in on ${row.date_label}`"
                    @click="openRow(row)"
                >
                    <ItemCard
                        :title="row.date_label"
                        :time="row.time_label || undefined"
                        :status="row.is_missed ? undefined : { kind: 'value', text: row.weight_label }"
                        :pending="pending && !row.is_missed"
                    >
                        <template #icon>
                            <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round">
                                <path d="M5 4h14l2 16H3z" />
                                <path d="M9 9a3 3 0 0 1 6 0M12 9l1.5-2" stroke-linecap="round" />
                            </svg>
                        </template>
                        <template v-if="row.is_missed || row.waist_label" #default>
                            <StatusPill v-if="row.is_missed" data-test="missed" :text="data.missed.text" :tone="data.missed.tone" />
                            <p v-else data-test="waist" class="text-sm text-secondary-text">Waist {{ row.waist_label }}</p>
                        </template>
                    </ItemCard>
                </button>
            </li>
        </ul>
        <p v-else-if="data.empty_message" class="px-1 text-secondary-text">{{ data.empty_message }}</p>

        <section data-test="steps" class="flex flex-col gap-2">
            <div class="flex items-center justify-between gap-2 px-1">
                <h2 class="text-xl font-semibold">{{ data.steps.title }}</h2>
                <button
                    type="button"
                    aria-label="Add steps"
                    class="flex min-h-tap min-w-tap items-center justify-center text-accent active:opacity-70"
                    @click="steps = { row: null }"
                >
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <path d="M12 5v14M5 12h14" />
                    </svg>
                </button>
            </div>
            <ul v-if="data.steps.rows.length" class="flex flex-col overflow-hidden rounded-2xl bg-surface">
                <li v-for="row in data.steps.rows" :key="row.date" class="border-secondary-text/15 [&+&]:border-t">
                    <button
                        type="button"
                        data-test="steps-row"
                        class="flex min-h-tap w-full items-center justify-between gap-3 px-4 py-2.5 text-left active:opacity-70"
                        :aria-label="`Edit the steps for ${row.date_label}`"
                        @click="steps = { row }"
                    >
                        <span class="flex flex-col">
                            <span class="text-primary-text">{{ row.date_label }}</span>
                            <span class="text-sm text-secondary-text">{{ row.source_label }}</span>
                        </span>
                        <span class="font-semibold tabular-nums">
                            <PendingMark v-if="pending" derived>{{ row.value_label }}</PendingMark>
                            <template v-else>{{ row.value_label }}</template>
                        </span>
                    </button>
                </li>
            </ul>
        </section>

        <WeighInEntrySheet
            :open="weighIn !== null"
            :units="data.units"
            :row="weighIn?.row ?? null"
            :at="weighIn?.at ?? null"
            :reload="reload"
            @cancel="closeWeighIn"
            @done="closeWeighIn"
        />
        <StepsEntrySheet
            :open="steps !== null"
            :row="steps?.row ?? null"
            :default-date="data.steps.default_date"
            :reload="reload"
            @cancel="steps = null"
            @done="steps = null"
        />
    </main>
</template>
