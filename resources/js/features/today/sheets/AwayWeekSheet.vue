<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';
import { useWrite } from '@/app/useWrite';
import type { AwayWeekSetInput } from '@/contracts/away_week_set';
import type { AwayWeekShowOutput } from '@/contracts/away_week_show';
import BottomSheet from '@/design/BottomSheet.vue';
import ChoiceList from '@/design/ChoiceList.vue';
import { toneClasses } from '@/design/tones';

// Mark plan weeks away (SCH-060, PLAN-025): which weeks, what happens to training and
// calories, and whether the phase keeps its end date. Ported from rp-clone's AwayWeekSheet.
// The server words every week and choice; the sheet keeps only what the user has picked.
const props = defineProps<{ payload?: AwayWeekShowOutput; weekStart?: string; page?: boolean }>();
const emit = defineEmits<{ close: [] }>();
// Opened at its own route, not over a screen: closing goes back (architecture.md, Shell slots).
const router = props.page ? useRouter() : null;

const { data, failure, load } = useRead<AwayWeekShowOutput>(
    'away_week_show',
    { week_start: props.weekStart ?? props.payload?.weeks.find((week) => week.selected)?.week_start },
    'The away weeks could not be loaded.',
    props.payload,
);
const { send, busy, error } = useWrite(load);

const open = ref(true);
const chosen = ref<string[]>([]);
const training = ref<AwayWeekShowOutput['training'] | null>(null);
const calories = ref<AwayWeekShowOutput['calories'] | null>(null);
const keepPhaseEnd = ref(false);

// The sheet opens with what the server chose; a read again after a write leaves the user's picks.
watch(
    data,
    (shown, before) => {
        if (!shown || before) return;
        chosen.value = shown.weeks.filter((week) => week.selected).map((week) => week.week_start);
        training.value = shown.training;
        calories.value = shown.calories;
    },
    { immediate: true },
);

const trainingChoices = computed(() =>
    (data.value?.training_options ?? []).map((option) => ({ id: option.value, title: option.label, detail: option.text, isEnabled: option.enabled })),
);
const calorieChoices = computed(() => (data.value?.calorie_options ?? []).map((option) => ({ id: option.value, title: option.label, detail: option.text })));
const editingWeeks = computed(() => (data.value?.weeks ?? []).filter((week) => chosen.value.includes(week.week_start)));
const picked = computed(() => chosen.value.length > 0);

function toggle(weekStart: string): void {
    chosen.value = chosen.value.includes(weekStart) ? chosen.value.filter((start) => start !== weekStart) : [...chosen.value, weekStart];
}

function close(): void {
    open.value = false;
    emit('close');
    router?.back();
}

async function save(): Promise<void> {
    if (!data.value || !picked.value || !training.value || !calories.value) return;
    const input: AwayWeekSetInput = {
        week_starts: data.value.weeks.filter((week) => chosen.value.includes(week.week_start)).map((week) => week.week_start),
        away: true,
        training: training.value,
        calories: calories.value,
        ...(data.value.shows_keep_phase_end ? { keep_phase_end: keepPhaseEnd.value } : {}),
    };
    if ((await send('away_week_set', { ...input }, 'The away weeks could not be saved.')) === 'done') close();
}

async function notAway(): Promise<void> {
    const input: AwayWeekSetInput = { week_starts: chosen.value, away: false };
    if ((await send('away_week_set', { ...input }, 'The week could not be changed.')) === 'done') close();
}
</script>

<template>
    <BottomSheet
        :open="open"
        :title="data?.is_editing ? 'Edit away week' : 'Mark weeks away'"
        :save-disabled="!data || !picked || busy"
        :cancel-disabled="busy"
        :error="error"
        @cancel="close"
        @save="save"
    >
        <div v-if="data" class="space-y-5 p-4" :aria-busy="busy || undefined">
            <section class="space-y-2">
                <h3 class="text-sm font-semibold text-secondary-text">{{ data.is_editing ? 'Week' : 'Weeks' }}</h3>
                <template v-if="data.is_editing">
                    <p v-for="week in editingWeeks" :key="week.week_start" data-test="editing-week">{{ week.label }}</p>
                </template>
                <p v-else-if="data.weeks.length === 0" class="text-secondary-text" data-test="no-weeks">No future plan weeks to mark.</p>
                <div v-else class="grid grid-cols-3 gap-2">
                    <button
                        v-for="week in data.weeks"
                        :key="week.week_start"
                        type="button"
                        data-test="week"
                        :aria-pressed="chosen.includes(week.week_start)"
                        :disabled="busy"
                        class="flex min-h-tap flex-col items-center justify-center rounded-xl border border-secondary-text/25 px-1 py-1.5 text-center text-sm font-semibold active:opacity-70"
                        :class="chosen.includes(week.week_start) ? 'bg-accent text-on-accent' : 'bg-surface text-primary-text'"
                        @click="toggle(week.week_start)"
                    >
                        {{ week.label }}
                        <span v-if="week.is_away" class="block text-xs font-normal">Away</span>
                    </button>
                </div>
            </section>

            <template v-if="picked">
                <section class="space-y-2">
                    <h3 class="text-sm font-semibold text-secondary-text" aria-hidden="true">Training</h3>
                    <ChoiceList v-model="training" label="Training" :choices="trainingChoices" :disabled="busy" />
                </section>
                <section class="space-y-2">
                    <h3 class="text-sm font-semibold text-secondary-text" aria-hidden="true">Calories</h3>
                    <ChoiceList v-model="calories" label="Calories" :choices="calorieChoices" :disabled="busy" />
                </section>
            </template>

            <ul v-if="data.notes.length" class="space-y-2" data-test="notes">
                <li v-for="note in data.notes" :key="note.text" class="rounded-xl p-3 text-sm" :class="toneClasses[note.tone]">{{ note.text }}</li>
            </ul>

            <label v-if="data.shows_keep_phase_end" class="flex min-h-tap items-center justify-between gap-3" data-test="keep-phase-end">
                <span>Keep the phase end date</span>
                <input v-model="keepPhaseEnd" type="checkbox" role="switch" class="peer sr-only" :disabled="busy" />
                <span
                    aria-hidden="true"
                    class="relative h-8 w-13 shrink-0 rounded-full bg-tone-neutral-bg transition-colors peer-checked:bg-accent peer-disabled:opacity-60 peer-focus-visible:ring-2 peer-focus-visible:ring-accent after:absolute after:top-0.5 after:left-0.5 after:size-7 after:rounded-full after:bg-white after:shadow after:transition-transform peer-checked:after:translate-x-5"
                />
            </label>

            <button
                v-if="data.is_editing && picked"
                type="button"
                class="min-h-tap w-full rounded-xl border border-secondary-text/25 font-semibold text-tone-warning active:opacity-70 disabled:opacity-50"
                data-test="not-away"
                :disabled="busy"
                @click="notAway"
            >
                Not away
            </button>
        </div>
        <div v-else class="p-4"><ReadState :failure="failure" what="The away weeks" @retry="load" /></div>
    </BottomSheet>
</template>
