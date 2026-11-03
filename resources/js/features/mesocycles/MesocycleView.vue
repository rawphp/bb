<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import ReadState from '@/app/ReadState.vue';
import { ulid } from '@/app/ulid';
import { useRead } from '@/app/useRead';
import { useWrite } from '@/app/useWrite';
import type { MesocycleDayAddInput } from '@/contracts/mesocycle_day_add';
import type { MesocycleEndInput } from '@/contracts/mesocycle_end';
import type { MesocycleLengthSetInput } from '@/contracts/mesocycle_length_set';
import type { MesocycleShowOutput } from '@/contracts/mesocycle_show';
import BottomSheet from '@/design/BottomSheet.vue';
import PendingMark from '@/design/PendingMark.vue';
import { toneClasses } from '@/design/tones';
import { PENDING_DIM } from './types';
import ActionMenu, { type ActionMenuItem } from '@/design/ActionMenu.vue';
import WeekColumn from './WeekColumn.vue';

// A mesocycle (MESO-040 to MESO-045): a column per week with each day's exercises, the
// sets per muscle, the calorie target and diet breaks; + and − for the length, and a
// menu to add a day, change progression rates or end it. Ported from rp-clone's MesocycleView.
// `pending` dims the server's numbers while a write waits to sync (OFF-008): the length
// text carries PendingMark, and the week count beside it dims the same way.
const props = withDefaults(defineProps<{ payload?: MesocycleShowOutput; mesocycleId?: string; pending?: boolean }>(), { pending: false });

const router = useRouter();
const { data, failure, load } = useRead<MesocycleShowOutput>(
    'mesocycle_show',
    { mesocycle_id: props.mesocycleId ?? props.payload?.id },
    'The mesocycle could not be loaded.',
    props.payload,
);

const { send, busy, error: errorMessage } = useWrite(load);
const endOpen = ref(false);
const weeks = ref<HTMLElement>();

// The new day's id (CAP-019), kept until the server has answered, so a retry after a call
// that got no answer sends the same id and cannot add a second day.
let dayId: string | null = null;

const menuItems = computed<ActionMenuItem[]>(() => [
    { key: 'add-day', title: 'Add day', disabled: !data.value?.saved?.can_add_day },
    { key: 'rates', title: 'Progression rates' },
    { key: 'end', title: 'End meso', disabled: !data.value?.saved?.end, destructive: true },
]);

// expected_weeks is the length on screen, so the server refuses the step if it has moved since (MESO-042).
// After a refusal the screen reads the server's copy, so the next step expects the length as it is now.
async function setLength(weeksCount: number): Promise<void> {
    const input = { mesocycle_id: data.value!.id, weeks: weeksCount, expected_weeks: data.value!.saved!.weeks_count } satisfies MesocycleLengthSetInput;

    if ((await send('mesocycle_length_set', input, 'The length could not be changed.')) === 'refused') await load({ fresh: true }).catch(() => undefined);
}

// How many days the mesocycle has, as the server last said.
const dayCount = (): number => data.value?.saved?.weeks[0]?.days.length ?? 0;

async function addDay(): Promise<void> {
    dayId ??= ulid();
    const before = dayCount();
    const outcome = await send('mesocycle_day_add', { mesocycle_id: data.value!.id, day_id: dayId } satisfies MesocycleDayAddInput, 'The day could not be added.');
    // The server answered, or its own copy read after no answer already shows the new
    // day: the id is used, and the next Add day is a new day. A cached copy never decides
    // this, as it may predate the write (useWrite reads the server after no answer).
    if (outcome === 'done' || outcome === 'refused' || (outcome === 'no-answer' && failure.value === null && dayCount() > before)) dayId = null;
}

function end(dietBreak: boolean): Promise<unknown> {
    endOpen.value = false;
    return send('mesocycle_end', { mesocycle_id: data.value!.id, diet_break: dietBreak } satisfies MesocycleEndInput, 'The mesocycle could not be ended.');
}

function onMenu(key: string): void {
    if (key === 'add-day') void addDay();
    else if (key === 'rates') void router.push(`/train/mesocycles/${data.value!.id}/rates`);
    else if (key === 'end') endOpen.value = true;
}

// The current week comes into view once, when the weeks first show. A read again
// (after a write, or on coming back to the screen) leaves the user where they scrolled.
let scrolled = false;
watch(
    () => data.value?.saved,
    async (saved) => {
        if (!saved || scrolled) return;
        scrolled = true;
        await nextTick();
        weeks.value?.querySelector<HTMLElement>('[data-now]')?.scrollIntoView?.({ inline: 'center', block: 'nearest' });
    },
    { immediate: true },
);
</script>

<template>
    <main class="relative py-3">
        <header class="flex items-center gap-2 px-4 pb-3">
            <span class="min-w-tap" aria-hidden="true" />
            <h1 class="flex-1 truncate text-center text-base font-semibold">{{ data?.name || 'Mesocycle' }}</h1>
            <ActionMenu label="Mesocycle menu" :items="menuItems" :disabled="!data?.saved || busy" @select="onMenu">
                <svg viewBox="0 0 24 24" class="size-7" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <circle cx="12" cy="12" r="10" />
                    <circle cx="7.5" cy="12" r="1" fill="currentColor" />
                    <circle cx="12" cy="12" r="1" fill="currentColor" />
                    <circle cx="16.5" cy="12" r="1" fill="currentColor" />
                </svg>
            </ActionMenu>
        </header>

        <div v-if="errorMessage" role="alert" class="mx-4 mb-3 flex items-start gap-2 rounded-2xl p-3" :class="toneClasses.warning" data-test="error">
            <p class="flex-1 text-sm"><span class="font-semibold">Something went wrong.</span> {{ errorMessage }}</p>
            <button type="button" class="min-h-tap px-2 font-semibold" @click="errorMessage = null">OK</button>
        </div>

        <template v-if="data?.saved">
            <section class="flex items-center gap-3 px-4" :aria-busy="busy || undefined">
                <div class="min-w-0 flex-1">
                    <h2 class="text-lg font-semibold text-primary-text">{{ data.saved.phase_title }}</h2>
                    <p class="text-sm text-secondary-text">
                        <PendingMark v-if="pending" derived>{{ data.saved.length_text }}</PendingMark>
                        <template v-else>{{ data.saved.length_text }}</template>
                    </p>
                </div>
                <svg viewBox="0 0 24 24" class="size-5 text-secondary-text" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <rect x="3.5" y="5" width="17" height="15" rx="2.5" />
                    <path d="M3.5 9.5h17M8 3v4M16 3v4" />
                </svg>
                <button
                    type="button"
                    class="flex min-h-tap min-w-tap items-center justify-center rounded-full bg-accent/15 text-xl text-accent disabled:text-secondary-text"
                    aria-label="Remove a week"
                    :disabled="!data.saved.can_shorten || busy"
                    data-test="shorten"
                    @click="setLength(data.saved.weeks_count - 1)"
                >
                    −
                </button>
                <span class="min-w-6 text-center text-lg font-semibold tabular-nums" :class="{ [PENDING_DIM]: pending }" :aria-label="`${data.saved.weeks_count} weeks`">
                    {{ data.saved.weeks_count }}
                </span>
                <button
                    type="button"
                    class="flex min-h-tap min-w-tap items-center justify-center rounded-full bg-accent/15 text-xl text-accent disabled:text-secondary-text"
                    aria-label="Add a week"
                    :disabled="!data.saved.can_lengthen || busy"
                    data-test="lengthen"
                    @click="setLength(data.saved.weeks_count + 1)"
                >
                    +
                </button>
            </section>

            <div ref="weeks" class="mt-4 flex snap-x gap-3 overflow-x-auto px-4 pb-2">
                <WeekColumn v-for="week in data.saved.weeks" :key="week.number" :week="week" :mesocycle-id="data.id" :pending="pending" />
            </div>
        </template>
        <p v-else-if="data" class="mx-4 rounded-2xl bg-surface p-4 text-secondary-text">This mesocycle is still being built.</p>
        <div v-else class="px-4"><ReadState :failure="failure" what="The mesocycle" @retry="load" /></div>

        <BottomSheet
            :open="endOpen && !!data?.saved?.end"
            title="End this mesocycle?"
            cancel-title="Keep going"
            :save-title="data?.saved?.end?.diet_break_offer ? null : 'End meso'"
            :heights="['half']"
            @cancel="endOpen = false"
            @save="end(false)"
        >
            <div v-if="data?.saved?.end" class="space-y-3 p-4 text-center" data-test="end-dialog">
                <p class="text-sm text-secondary-text">{{ data.saved.end.warning }}</p>
                <template v-if="data.saved.end.diet_break_offer">
                    <p class="text-sm text-secondary-text">{{ data.saved.end.diet_break_offer }}</p>
                    <button type="button" class="min-h-tap w-full rounded-xl" :class="toneClasses.warning" data-test="end-with-break" @click="end(true)">
                        End and start a diet break next week
                    </button>
                    <button type="button" class="min-h-tap w-full rounded-xl" :class="toneClasses.warning" data-test="end-without-break" @click="end(false)">
                        End without a diet break
                    </button>
                </template>
            </div>
        </BottomSheet>
    </main>
</template>
