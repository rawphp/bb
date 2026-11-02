<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import { write } from '@/app/call';
import { failureMessage } from '@/app/errors';
import { ulid } from '@/app/ulid';
import type { MesocycleDayAddInput } from '@/contracts/mesocycle_day_add';
import type { MesocycleEndInput } from '@/contracts/mesocycle_end';
import type { MesocycleLengthSetInput } from '@/contracts/mesocycle_length_set';
import type { MesocycleShowOutput } from '@/contracts/mesocycle_show';
import ReadState from './ReadState.vue';
import { useScreenRead } from './useScreenRead';
import WeekColumn from './WeekColumn.vue';

// A mesocycle (MESO-040 to MESO-045): a column per week with each day's exercises, the
// sets per muscle, the calorie target and diet breaks; + and − for the length, and a
// menu to add a day, change progression rates or end it. Ported from rp-clone's MesocycleView.
// `pending` dims the server's numbers while a write waits to sync (OFF-008).
const props = withDefaults(defineProps<{ payload?: MesocycleShowOutput; mesocycleId?: string; pending?: boolean }>(), { pending: false });

const { data, failure, load } = useScreenRead<MesocycleShowOutput>(
    'mesocycle_show',
    () => ({ mesocycle_id: props.mesocycleId ?? props.payload?.id }),
    props.payload,
);

const menuOpen = ref(false);
const endOpen = ref(false);
const busy = ref(false);
const errorMessage = ref<string | null>(null);
const weeks = ref<HTMLElement>();

async function send(name: string, input: Record<string, unknown>, fallback: string): Promise<void> {
    menuOpen.value = false;
    endOpen.value = false;
    busy.value = true;
    try {
        await write(name, input);
        await load();
    } catch (error) {
        errorMessage.value = failureMessage(error, fallback);
    } finally {
        busy.value = false;
    }
}

function setLength(weeksCount: number): Promise<void> {
    return send('mesocycle_length_set', { mesocycle_id: data.value!.id, weeks: weeksCount } satisfies MesocycleLengthSetInput, 'The length could not be changed.');
}

function addDay(): Promise<void> {
    return send('mesocycle_day_add', { mesocycle_id: data.value!.id, day_id: ulid() } satisfies MesocycleDayAddInput, 'The day could not be added.');
}

function end(dietBreak: boolean): Promise<void> {
    return send('mesocycle_end', { mesocycle_id: data.value!.id, diet_break: dietBreak } satisfies MesocycleEndInput, 'The mesocycle could not be ended.');
}

// The current week comes into view first.
watch(
    () => data.value?.saved,
    async (saved) => {
        if (!saved) return;
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
            <div class="relative">
                <button
                    type="button"
                    class="flex min-h-tap min-w-tap items-center justify-center rounded-full text-primary-text disabled:opacity-50"
                    aria-label="Mesocycle menu"
                    :aria-expanded="menuOpen"
                    :disabled="!data?.saved || busy"
                    data-test="menu"
                    @click="menuOpen = !menuOpen"
                >
                    <svg viewBox="0 0 24 24" class="size-7" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <circle cx="12" cy="12" r="10" />
                        <circle cx="7.5" cy="12" r="1" fill="currentColor" />
                        <circle cx="12" cy="12" r="1" fill="currentColor" />
                        <circle cx="16.5" cy="12" r="1" fill="currentColor" />
                    </svg>
                </button>
                <div v-if="menuOpen && data?.saved" role="menu" class="absolute right-0 z-10 mt-1 w-60 overflow-hidden rounded-xl bg-surface shadow-lg">
                    <button
                        type="button"
                        role="menuitem"
                        class="flex min-h-tap w-full items-center px-4 text-left text-primary-text disabled:text-secondary-text"
                        :disabled="!data.saved.can_add_day"
                        data-test="add-day"
                        @click="addDay"
                    >
                        Add day
                    </button>
                    <RouterLink
                        role="menuitem"
                        class="flex min-h-tap w-full items-center px-4 text-primary-text"
                        :to="`/train/mesocycles/${data.id}/rates`"
                        data-test="rates"
                    >
                        Progression rates
                    </RouterLink>
                    <button
                        type="button"
                        role="menuitem"
                        class="flex min-h-tap w-full items-center px-4 text-left text-tone-warning disabled:text-secondary-text"
                        :disabled="!data.saved.end"
                        data-test="end"
                        @click="(menuOpen = false), (endOpen = true)"
                    >
                        End meso
                    </button>
                </div>
            </div>
        </header>

        <template v-if="data?.saved">
            <section class="flex items-center gap-3 px-4" :aria-busy="busy || undefined">
                <div class="min-w-0 flex-1">
                    <h2 class="text-lg font-semibold text-primary-text">{{ data.saved.phase_title }}</h2>
                    <p class="text-sm text-secondary-text" :class="{ 'opacity-50': pending }">{{ data.saved.length_text }}</p>
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
                <span class="min-w-6 text-center text-lg font-semibold tabular-nums" :class="{ 'opacity-50': pending }" :aria-label="`${data.saved.weeks_count} weeks`">
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
            <p v-if="pending" class="px-4 text-sm text-secondary-text">Waiting to sync</p>

            <div ref="weeks" class="mt-4 flex snap-x gap-3 overflow-x-auto px-4 pb-2">
                <WeekColumn v-for="week in data.saved.weeks" :key="week.number" :week="week" :mesocycle-id="data.id" :pending="pending" />
            </div>
        </template>
        <p v-else-if="data" class="mx-4 rounded-2xl bg-surface p-4 text-secondary-text">This mesocycle is still being built.</p>
        <div v-else class="px-4"><ReadState :failure="failure" what="The mesocycle" @retry="load" /></div>

        <div v-if="endOpen && data?.saved?.end" class="fixed inset-0 z-20 flex items-end bg-black/30 p-3" data-test="end-dialog">
            <div role="alertdialog" aria-modal="true" aria-labelledby="end-title" class="w-full space-y-2">
                <div class="space-y-3 rounded-2xl bg-surface p-4 text-center">
                    <h2 id="end-title" class="text-sm font-semibold text-secondary-text">End this mesocycle?</h2>
                    <p class="text-sm text-secondary-text">{{ data.saved.end.warning }}</p>
                    <p v-if="data.saved.end.diet_break_offer" class="text-sm text-secondary-text">{{ data.saved.end.diet_break_offer }}</p>
                    <template v-if="data.saved.end.diet_break_offer">
                        <button type="button" class="min-h-tap w-full text-tone-warning" data-test="end-with-break" @click="end(true)">
                            End and start a diet break next week
                        </button>
                        <button type="button" class="min-h-tap w-full text-tone-warning" data-test="end-without-break" @click="end(false)">
                            End without a diet break
                        </button>
                    </template>
                    <button v-else type="button" class="min-h-tap w-full text-tone-warning" data-test="end-confirm" @click="end(false)">End meso</button>
                </div>
                <button type="button" class="min-h-tap w-full rounded-2xl bg-surface font-semibold text-accent" data-test="keep-going" @click="endOpen = false">
                    Keep going
                </button>
            </div>
        </div>

        <div v-if="errorMessage" class="fixed inset-0 z-20 flex items-center justify-center bg-black/30 p-8">
            <div role="alertdialog" aria-modal="true" aria-labelledby="error-title" class="w-full space-y-3 rounded-2xl bg-surface p-4 text-center">
                <h2 id="error-title" class="font-semibold">Something went wrong</h2>
                <p class="text-sm">{{ errorMessage }}</p>
                <button type="button" class="min-h-tap w-full font-semibold text-accent" @click="errorMessage = null">OK</button>
            </div>
        </div>
    </main>
</template>
