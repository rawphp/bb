<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import ReadState from '@/app/ReadState.vue';
import { ulid } from '@/app/ulid';
import { useRead } from '@/app/useRead';
import { useWrite } from '@/app/useWrite';
import type { PhaseTransitionCompleteInput } from '@/contracts/phase_transition_complete';
import type { PlanShowOutput } from '@/contracts/plan_show';
import BottomSheet from '@/design/BottomSheet.vue';
import ChoiceList, { type Choice } from '@/design/ChoiceList.vue';
import PendingMark from '@/design/PendingMark.vue';
import { toneClasses } from '@/design/tones';
import { selectedIndex, useLeave } from './planScreen';

// The sheet End phase, "Choose a new goal" and "End the plan" open (PH-007, PH-030 to
// PH-046): what the phase achieved, what comes next, and Confirm. The server sends every
// next phase with its lengths, calories, training, warnings and readiness questions
// (`plan_show.transition`), so picking one only shows what it already sent. `pending`
// dims the server's numbers while a write waits to sync (OFF-008). Ported from
// rp-clone's PhaseTransitionView.
const props = withDefaults(defineProps<{ payload?: PlanShowOutput; pending?: boolean }>(), { pending: false });

type CalorieMode = NonNullable<PhaseTransitionCompleteInput['calorie_mode']>;

const leave = useLeave();
const { data, failure, load } = useRead<PlanShowOutput>('plan_show', {}, 'Your plan could not be loaded.', props.payload);
const { send, busy, error } = useWrite(load);

// The next phase's id, made once, so a retry after no answer is the same create (CAP-019).
const nextPhaseId = ulid();
// Indexes into what the server sent; null until the user picks, so the server's choice shows.
const optionPick = ref<number | null>(null);
const lengthPick = ref<number | null>(null);
const templatePick = ref<number | null>(null);
const stepPick = ref<number | null>(null);
// PH-044 steps calories up by default. plan_show sends no default mode for step_up, so
// this is the one default the screen holds until the contract carries it (#79).
const calorieMode = ref<CalorieMode>('step_up');
// The readiness answers (PH-046), by question.
const answers = ref<Record<string, 'yes' | 'no'>>({});

const transition = computed(() => data.value?.transition ?? null);
const options = computed(() => transition.value?.options ?? []);
const optionIndex = computed(() => selectedIndex(optionPick.value, options.value));
const option = computed(() => options.value[optionIndex.value]);
const lengthIndex = computed(() => selectedIndex(lengthPick.value, option.value?.lengths ?? []));
const length = computed(() => option.value?.lengths[lengthIndex.value]);
const templateIndex = computed(() => selectedIndex(templatePick.value, option.value?.templates ?? []));
const template = computed(() => option.value?.templates[templateIndex.value]);
const stepIndex = computed(() => selectedIndex(stepPick.value, option.value?.step_up?.steps ?? []));
const step = computed(() => option.value?.step_up?.steps[stepIndex.value]);
const warnings = computed(() => [...(option.value?.warnings ?? []), ...(length.value?.warnings ?? [])]);
const saidNo = computed(() => option.value?.readiness.filter((question) => answers.value[question.text] === 'no') ?? []);
const maintenanceIndex = computed(() => options.value.findIndex((o) => o.choice === 'maintenance' && !o.gain_first));

const summary = computed(() =>
    transition.value
        ? [
              ['Length', transition.value.length_text],
              ['Weight change', transition.value.weight_change_text],
              ['Rate achieved', transition.value.rate_text],
              ['Adherence', transition.value.adherence_text],
              ['Completed', transition.value.mesocycles_text],
          ].filter((row): row is [string, string] => !!row[1])
        : [],
);

const optionChoices = computed<Choice<number>[]>(() =>
    options.value.map((o, index) => ({ id: index, title: o.title, detail: o.detail || undefined, isRecommended: o.is_recommended })),
);
const lengthChoices = computed<Choice<number>[]>(() => option.value?.lengths.map((l, index) => ({ id: index, title: l.label, detail: l.ends_text })) ?? []);
const templateChoices = computed<Choice<number>[]>(() => option.value?.templates.map((t, index) => ({ id: index, title: t.title })) ?? []);
const stepChoices = computed<Choice<number>[]>(() => option.value?.step_up?.steps.map((s, index) => ({ id: index, title: s.step.text })) ?? []);
const modeChoices: Choice<CalorieMode>[] = [
    { id: 'step_up', title: 'Step up' },
    { id: 'straight', title: 'Go straight to maintenance' },
];
const yesNo: Choice<'yes' | 'no'>[] = [
    { id: 'yes', title: 'Yes' },
    { id: 'no', title: 'No' },
];

// Another next phase starts from the server's length, template and step for it.
watch(optionPick, () => {
    lengthPick.value = null;
    templatePick.value = null;
    stepPick.value = null;
    calorieMode.value = 'step_up';
    answers.value = {};
});

const canConfirm = computed(() => !!transition.value && !!option.value && !option.value.refusal && !busy.value);

async function confirm(): Promise<void> {
    const ended = transition.value;
    const next = option.value;
    if (!ended || !next || !canConfirm.value) return;
    const stepsUp = !!next.step_up && calorieMode.value === 'step_up';
    const input = {
        phase_id: ended.phase_id,
        next_phase_id: nextPhaseId,
        choice: next.choice,
        gain_first: next.gain_first,
        weeks: length.value?.weeks ?? null,
        template: template.value?.template ?? 'standard',
        calorie_mode: next.step_up ? calorieMode.value : null,
        step_kcal: stepsUp ? (step.value?.step.value ?? null) : null,
    } satisfies PhaseTransitionCompleteInput;
    if ((await send('phase_transition_complete', input, 'The next phase could not be set.')) === 'done') leave();
}

const heading = 'px-1 text-sm font-medium text-secondary-text';
const card = 'rounded-xl bg-surface px-4 py-3';
const dim = computed(() => (props.pending ? 'opacity-60' : ''));
</script>

<template>
    <BottomSheet
        :open="true"
        :title="transition?.title ?? 'End phase'"
        save-title="Confirm"
        :save-disabled="!canConfirm"
        :cancel-disabled="busy"
        :dismissible="!busy"
        :error="error"
        @cancel="leave"
        @save="confirm"
    >
        <div v-if="transition && option" class="space-y-6 bg-background px-4 py-4">
            <section class="space-y-2" aria-labelledby="transition-summary">
                <h2 id="transition-summary" :class="heading">
                    How it went
                    <PendingMark v-if="pending" />
                </h2>
                <ul class="divide-y divide-secondary-text/15 rounded-xl bg-surface" :class="dim" data-test="summary">
                    <li v-for="[title, value] in summary" :key="title" class="flex min-h-tap items-center justify-between gap-3 px-4 py-2">
                        <span>{{ title }}</span>
                        <span class="text-right text-secondary-text">{{ value }}</span>
                    </li>
                </ul>
            </section>

            <section class="space-y-2" aria-labelledby="transition-next">
                <h2 id="transition-next" :class="heading">{{ transition.next_from_text }}</h2>
                <ChoiceList label="Next phase" :choices="optionChoices" :model-value="optionIndex" :disabled="busy" data-test="options" @update:model-value="optionPick = $event" />
            </section>

            <section v-if="option.lengths.length" class="space-y-2" aria-labelledby="transition-length">
                <h2 id="transition-length" :class="heading">Length</h2>
                <ChoiceList
                    label="Length"
                    :choices="lengthChoices"
                    :model-value="lengthIndex"
                    :disabled="busy"
                    :class="dim"
                    data-test="lengths"
                    @update:model-value="lengthPick = $event"
                />
            </section>

            <section v-if="option.starting_calories_text" class="space-y-2" aria-labelledby="transition-calories">
                <h2 id="transition-calories" :class="heading">Starting calories</h2>
                <p :class="[card, dim]" data-test="calories">{{ option.starting_calories_text }}</p>
            </section>

            <section v-if="option.step_up" class="space-y-2" aria-labelledby="transition-step-up" data-test="step-up">
                <h2 id="transition-step-up" :class="heading">Calories on maintenance</h2>
                <ChoiceList v-model="calorieMode" label="Calories" :choices="modeChoices" :disabled="busy" data-test="calorie-mode" />
                <p class="px-1 text-sm text-secondary-text">{{ option.step_up.explanation }}</p>
                <template v-if="calorieMode === 'step_up' && step">
                    <ChoiceList label="Step" :choices="stepChoices" :model-value="stepIndex" :disabled="busy" data-test="steps" @update:model-value="stepPick = $event" />
                    <ul class="divide-y divide-secondary-text/15 rounded-xl bg-surface" :class="dim" data-test="schedule">
                        <li v-for="week in step.schedule" :key="week.label" class="flex min-h-tap items-center justify-between px-4 py-2">
                            <span>{{ week.label }}</span>
                            <span class="tabular-nums text-secondary-text">{{ week.kcal_text }}</span>
                        </li>
                    </ul>
                </template>
            </section>

            <section v-if="option.corridor_text" class="space-y-2" aria-labelledby="transition-corridor">
                <h2 id="transition-corridor" :class="heading">Hold your weight</h2>
                <p :class="[card, dim]" data-test="corridor">{{ option.corridor_text }}</p>
            </section>

            <section v-if="template" class="space-y-2" aria-labelledby="transition-training">
                <h2 id="transition-training" :class="heading">The next mesocycle starts from</h2>
                <ChoiceList
                    v-if="templateChoices.length > 1"
                    label="Template"
                    :choices="templateChoices"
                    :model-value="templateIndex"
                    :disabled="busy"
                    data-test="templates"
                    @update:model-value="templatePick = $event"
                />
                <p v-if="template.detail" class="px-1 text-sm" data-test="template-detail">{{ template.detail }}</p>
                <ul class="space-y-1 text-sm" :class="card" data-test="training">
                    <li v-for="line in template.training_lines" :key="line.text">{{ line.text }}</li>
                </ul>
            </section>

            <section v-if="option.readiness.length" class="space-y-3" aria-labelledby="transition-readiness" data-test="readiness">
                <h2 id="transition-readiness" :class="heading">Ready for a new phase?</h2>
                <div v-for="question in option.readiness" :key="question.text" class="space-y-2">
                    <p class="px-1">{{ question.text }}</p>
                    <ChoiceList
                        :label="question.text"
                        :choices="yesNo"
                        :model-value="answers[question.text] ?? null"
                        :disabled="busy"
                        @update:model-value="answers[question.text] = $event"
                    />
                </div>
                <template v-if="saidNo.length">
                    <ul class="space-y-2">
                        <li v-for="question in saidNo" :key="question.text" class="rounded-xl p-3 text-sm" :class="toneClasses.warning">{{ question.warning }}</li>
                    </ul>
                    <button
                        v-if="maintenanceIndex >= 0"
                        type="button"
                        class="min-h-tap w-full rounded-xl border border-accent px-4 text-left font-medium text-accent active:opacity-70 disabled:opacity-50"
                        data-test="maintenance-first"
                        :disabled="busy"
                        @click="optionPick = maintenanceIndex"
                    >
                        Choose Maintenance first
                    </button>
                </template>
            </section>

            <ul v-if="warnings.length" class="space-y-2" data-test="warnings">
                <li v-for="warning in warnings" :key="warning.text" class="rounded-xl p-3 text-sm" :class="toneClasses[warning.tone]">{{ warning.text }}</li>
            </ul>
            <p v-if="option.refusal" class="rounded-xl p-3 text-sm" :class="toneClasses.warning" data-test="refusal">{{ option.refusal }}</p>
            <p v-if="option.note" class="px-1 text-sm text-secondary-text" data-test="note">{{ option.note }}</p>
        </div>

        <p v-else-if="data" class="p-6 text-center text-secondary-text" data-test="no-transition">No phase is ending.</p>
        <div v-else class="p-4"><ReadState :failure="failure" what="Your plan" @retry="load" /></div>
    </BottomSheet>
</template>
