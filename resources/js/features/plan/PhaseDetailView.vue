<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import ReadState from '@/app/ReadState.vue';
import { ulid } from '@/app/ulid';
import { useRead } from '@/app/useRead';
import { useWrite } from '@/app/useWrite';
import type { PhaseEndInput } from '@/contracts/phase_end';
import type { PhaseEventDateSetInput } from '@/contracts/phase_event_date_set';
import type { PhaseGoalChangeInput } from '@/contracts/phase_goal_change';
import type { PhaseLengthSetInput } from '@/contracts/phase_length_set';
import type { PhaseShowOutput } from '@/contracts/phase_show';
import BottomSheet from '@/design/BottomSheet.vue';
import PendingMark from '@/design/PendingMark.vue';
import ProgressBar from '@/design/ProgressBar.vue';
import { toneClasses } from '@/design/tones';
import AdjustLengthSheet from './AdjustLengthSheet.vue';
import ChangeGoalSheet from './ChangeGoalSheet.vue';
import { FLAG, phaseTints } from './phaseTints';

// A phase (PLAN-004): its type, dates, rate, weights and progress, and the actions on it:
// adjust length (PH-006), change goal (PH-009), end phase (PH-007) and the event date
// (PH-043, PLAN-028). The server says which lengths and goals there are and whether the
// phase can end. `opening` starts with an action open, for the More menu's shortcuts.
// `pending` dims the server's numbers while a write waits to sync (OFF-008). Ported from
// rp-clone's PhaseDetailView.
const props = withDefaults(
    defineProps<{ payload?: PhaseShowOutput; phaseId?: string; pending?: boolean; opening?: 'adjust-length' | 'end' }>(),
    { pending: false, opening: undefined },
);

const router = useRouter();
const { data, failure, load } = useRead<PhaseShowOutput>('phase_show', { phase_id: props.phaseId ?? props.payload?.id }, 'This phase could not be loaded.', props.payload);
const { send, busy, error } = useWrite(load);

type Sheet = 'length' | 'goal' | 'event' | 'end';
const sheet = ref<Sheet | null>(null);
// The length the length sheet opens on: the event week's (PLAN-028), else the phase's own.
const lengthWeeks = ref<number | undefined>(undefined);
const eventDate = ref('');

// The new phase a goal change makes (CAP-019), with the goal it was made for. It is kept
// until the server has answered, so a retry of the same change after no answer sends the
// same id and cannot make a second phase; a change to another goal gets its own id.
let newPhase: { type: PhaseGoalChangeInput['type']; id: string } | null = null;

const rows = computed(() => {
    const phase = data.value;
    if (!phase) return [];
    const now = phase.current_weight_text && (phase.change_text ? `${phase.current_weight_text} (${phase.change_text})` : phase.current_weight_text);
    return [
        { title: 'Type', value: phase.title },
        { title: 'Length', value: phase.length_text },
        { title: 'Rate', value: phase.rate_text },
        { title: 'Start weight', value: phase.start_weight_text },
        { title: 'Target weight', value: phase.target_weight_text },
        { title: 'Now', value: now },
    ].filter((row) => row.value);
});

function open(which: Sheet, weeks?: number): void {
    error.value = null;
    lengthWeeks.value = weeks;
    eventDate.value = '';
    sheet.value = which;
}

async function write(name: string, input: Record<string, unknown>, fallback: string): Promise<boolean> {
    const done = (await send(name, input, fallback)) === 'done';
    if (done) sheet.value = null;
    return done;
}

function setLength(weeks: PhaseLengthSetInput['weeks'], fix: PhaseLengthSetInput['fix']): void {
    void write('phase_length_set', { phase_id: data.value!.id, weeks, fix } satisfies PhaseLengthSetInput, 'The phase length could not be changed.');
}

async function changeGoal(type: PhaseGoalChangeInput['type']): Promise<void> {
    if (newPhase?.type !== type) newPhase = { type, id: ulid() };
    const input = { phase_id: data.value!.id, type, new_phase_id: newPhase.id } satisfies PhaseGoalChangeInput;
    const outcome = await send('phase_goal_change', input, 'The goal could not be changed.');
    if (outcome === 'done' || outcome === 'refused') newPhase = null;
    if (outcome === 'done') sheet.value = null;
}

function setEvent(date: string | null): void {
    void write('phase_event_date_set', { phase_id: data.value!.id, event_date: date } satisfies PhaseEventDateSetInput, 'The event date could not be saved.');
}

// PH-007: the phase ends this week, and the phase transition (PH-030) takes over.
async function endPhase(): Promise<void> {
    if (await write('phase_end', { phase_id: data.value!.id } satisfies PhaseEndInput, 'The phase could not be ended.')) void router.push('/train/plan/transition');
}

// The action the screen was opened for, once, when the phase first shows.
let opened = false;
watch(
    data,
    (phase) => {
        if (!phase || opened || !props.opening) return;
        opened = true;
        if (props.opening === 'adjust-length') open('length');
        else if (phase.can_end) open('end');
    },
    { immediate: true },
);
</script>

<template>
    <main class="bb-screen-content">
        <header class="mb-4 flex items-center gap-2">
            <span class="min-w-tap" aria-hidden="true" />
            <h1 class="flex-1 truncate text-center text-base font-semibold">{{ data?.title ?? 'Phase' }}</h1>
            <span class="min-w-tap" aria-hidden="true" />
        </header>

        <div v-if="error && !sheet" role="alert" class="mb-4 flex items-start gap-2 rounded-2xl p-3" :class="toneClasses.warning" data-test="error">
            <p class="flex-1 text-sm"><span class="font-semibold">Something went wrong.</span> {{ error }}</p>
            <button type="button" class="min-h-tap px-2 font-semibold" @click="error = null">OK</button>
        </div>

        <template v-if="data">
            <section class="mb-6 rounded-2xl bg-surface px-4" aria-label="Phase">
                <div class="space-y-2 border-b border-secondary-text/15 py-3">
                    <p class="font-semibold" :class="{ 'opacity-60': pending }" data-test="dates">{{ data.dates_text }}</p>
                    <ProgressBar
                        v-if="data.progress != null"
                        title="Phase progress"
                        :value-text="data.progress_text"
                        :fraction="data.progress"
                        :tint="phaseTints[data.type].bar"
                        :pending="pending"
                        class="text-secondary-text"
                        data-test="progress"
                    />
                    <p v-else class="text-sm text-secondary-text" data-test="progress">
                        <PendingMark v-if="pending" derived>{{ data.progress_text }}</PendingMark>
                        <template v-else>{{ data.progress_text }}</template>
                    </p>
                </div>
                <div class="divide-y divide-secondary-text/15" :class="{ 'opacity-60': pending }" data-test="rows">
                    <div v-for="row in rows" :key="row.title" class="flex min-h-tap items-center gap-2 py-2" data-test="row"><span class="flex-1">{{ row.title }}</span> <span class="text-right text-secondary-text">{{ row.value }}</span></div>
                </div>
            </section>

            <section class="mb-6" aria-labelledby="event-heading">
                <h2 id="event-heading" class="px-4 pb-1.5 text-sm font-semibold text-secondary-text">Event</h2>
                <div class="divide-y divide-secondary-text/15 overflow-hidden rounded-2xl bg-surface px-4">
                    <template v-if="data.event_date">
                        <div class="space-y-1 py-3" data-test="event">
                            <div class="flex min-h-tap items-center gap-2"><span class="flex-1">Event date</span> <span class="text-secondary-text">{{ data.event_date }}</span></div>
                            <p v-if="data.event_text" class="flex items-center gap-2 text-sm">
                                <svg viewBox="0 0 24 24" class="size-4 shrink-0" fill="currentColor" aria-hidden="true"><path :d="FLAG" /></svg>
                                {{ data.event_text }}
                            </p>
                        </div>
                        <button type="button" class="min-h-tap w-full text-left text-tone-warning" :disabled="busy" data-test="remove-event" @click="setEvent(null)">Remove event date</button>
                    </template>
                    <button v-else type="button" class="flex min-h-tap w-full items-center gap-3 text-left text-accent" data-test="add-event" @click="open('event')">
                        <svg viewBox="0 0 24 24" class="size-6 shrink-0" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" aria-hidden="true">
                            <path d="M5 21V4h11l-1.5 4L16 12H5" />
                        </svg>
                        Add an event date
                    </button>
                    <button
                        v-if="data.event_offer"
                        type="button"
                        class="min-h-tap w-full py-2 text-left font-semibold text-accent"
                        data-test="event-offer"
                        @click="open('length', data.event_offer.weeks)"
                    >
                        {{ data.event_offer.text }}
                    </button>
                </div>
            </section>

            <section v-if="data.warnings.length" class="mb-6 space-y-2" data-test="warnings">
                <p v-for="warning in data.warnings" :key="warning.text" class="rounded-2xl p-3 text-[13px]" :class="toneClasses[warning.tone]">{{ warning.text }}</p>
            </section>

            <nav class="divide-y divide-secondary-text/15 overflow-hidden rounded-2xl bg-surface px-4" aria-label="Phase actions">
                <button type="button" class="flex min-h-tap w-full items-center gap-3 text-left text-accent" data-test="adjust-length" @click="open('length')">
                    <svg viewBox="0 0 24 24" class="size-6 shrink-0" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
                        <path d="M3 12h18M7 8l-4 4 4 4M17 8l4 4-4 4" />
                    </svg>
                    Adjust length
                </button>
                <button v-if="data.goals.length" type="button" class="flex min-h-tap w-full items-center gap-3 text-left text-accent" data-test="change-goal" @click="open('goal')">
                    <svg viewBox="0 0 24 24" class="size-6 shrink-0" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
                        <path d="M4 8h14l-3-3M20 16H6l3 3" />
                    </svg>
                    Change goal
                </button>
                <button v-if="data.can_end" type="button" class="flex min-h-tap w-full items-center gap-3 text-left text-tone-warning" data-test="end-phase" @click="open('end')">
                    <svg viewBox="0 0 24 24" class="size-6 shrink-0" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" aria-hidden="true">
                        <path d="M5 21V4h14v9H5M5 4h4.5v4.5H5zm4.5 4.5H14V13H9.5zm4.5-4.5h5v4.5h-5z" />
                    </svg>
                    End phase
                </button>
            </nav>

            <AdjustLengthSheet v-if="sheet === 'length'" :lengths="data.lengths" :weeks="lengthWeeks" :busy="busy" :error="error" @apply="setLength" @close="sheet = null" />
            <ChangeGoalSheet v-if="sheet === 'goal'" :goals="data.goals" :busy="busy" :error="error" @change="changeGoal" @close="sheet = null" />

            <BottomSheet
                :open="sheet === 'event'"
                title="Event date"
                :save-disabled="!eventDate || busy"
                :cancel-disabled="busy"
                :dismissible="!busy"
                :heights="['half']"
                @cancel="sheet = null"
                @save="setEvent(eventDate)"
            >
                <div class="space-y-3 p-4" data-test="event-sheet">
                    <label class="flex min-h-tap items-center gap-3">
                        <span class="flex-1">Event date</span>
                        <input v-model="eventDate" type="date" class="min-h-tap rounded-xl bg-surface px-3" />
                    </label>
                    <p v-if="error" role="alert" class="rounded-xl p-3 text-sm" :class="toneClasses.warning">{{ error }}</p>
                </div>
            </BottomSheet>

            <BottomSheet
                :open="sheet === 'end'"
                title="End this phase?"
                cancel-title="Keep going"
                save-title="End phase"
                :save-disabled="busy"
                :cancel-disabled="busy"
                :dismissible="!busy"
                :heights="['half']"
                @cancel="sheet = null"
                @save="endPhase"
            >
                <div class="space-y-3 p-4 text-center" data-test="end-sheet">
                    <p class="text-sm text-secondary-text">{{ data.title }} ends this week. This cannot be undone.</p>
                    <p v-if="error" role="alert" class="rounded-xl p-3 text-sm" :class="toneClasses.warning">{{ error }}</p>
                </div>
            </BottomSheet>
        </template>
        <ReadState v-else :failure="failure" what="This phase" @retry="load" />
    </main>
</template>
