<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import ReadState from '@/app/ReadState.vue';
import { screenRead, type ReadFailure } from '@/app/screenRead';
import { ulid } from '@/app/ulid';
import { useWrite } from '@/app/useWrite';
import type { MesocycleBuildInput } from '@/contracts/mesocycle_build';
import type { MesocycleCopyInput } from '@/contracts/mesocycle_copy';
import type { MesocycleShowOutput } from '@/contracts/mesocycle_show';
import type { MesocycleStructureGenerateInput } from '@/contracts/mesocycle_structure_generate';
import type { MesocycleSlotsFillInput } from '@/contracts/mesocycle_slots_fill';
import type { PrioritySetInput } from '@/contracts/priority_set';
import StepFlow, { type StepFlowStep } from '@/design/StepFlow.vue';
import TemplateDetailView from '../templates/TemplateDetailView.vue';
import TemplateLibraryView from '../templates/TemplateLibraryView.vue';
import BuilderStepView from './BuilderStepView.vue';
import DetailsStepView from './DetailsStepView.vue';
import ScratchStepView from './ScratchStepView.vue';
import SlotsStepView from './SlotsStepView.vue';
import { STEPS, type BuildDaysInput, type BuildRoute, type BuildStep } from './types';

// The create flow for all four routes (MESO-002 to MESO-007, MESO-013, MESO-014,
// MESO-020 to MESO-023), ported from rp-clone's MesocycleBuildView. Each route starts on
// its own step and they meet at the exercises and the details (UI-007: every step is a
// StepFlow). The draft and every number on it are the server's (VIEW-002): the script
// holds the route and draft from the address, the step, the template being looked at and
// the name being typed. The flow opens at /train/mesocycles/build?route=<template|copy_previous|builder|scratch>
// [&copy=<source mesocycle id>], or ?draft=<draft id> for the unfinished draft.
// `payload` is a /dev/screens fixture: it is shown as it is, and nothing is created or read.
const props = withDefaults(
    defineProps<{ payload?: MesocycleShowOutput; route?: string; copy?: string; draft?: string; step?: string; pending?: boolean }>(),
    { payload: undefined, route: undefined, copy: undefined, draft: undefined, step: undefined, pending: false },
);

const router = useRouter();

// The words of each step. The server sends no step object for this flow, so the counters
// are written out per route, as the flow's steps are.
const TITLES: Record<BuildStep, string> = {
    template: 'Choose a template',
    builder: 'Meso Builder',
    scratch: 'Build from scratch',
    slots: 'Exercises',
    details: 'Details',
};
const THREE = { slots: 'Step 2 of 3', details: 'Step 3 of 3' };
const COUNTERS: Record<BuildRoute, Partial<Record<BuildStep, string>>> = {
    template: { template: 'Step 1 of 3', ...THREE },
    builder: { builder: 'Step 1 of 3', ...THREE },
    scratch: { scratch: 'Step 1 of 3', ...THREE },
    copy_previous: { slots: 'Step 1 of 2', details: 'Step 2 of 2' },
};
const FIRST_STEP: Record<BuildRoute, BuildStep> = { template: 'template', builder: 'builder', scratch: 'scratch', copy_previous: 'slots' };
// A first step belongs to one route, whatever the draft says (the dev screens open any step on any fixture).
const OWN_ROUTE: Partial<Record<BuildStep, BuildRoute>> = { template: 'template', builder: 'builder', scratch: 'scratch' };

const isRoute = (value: unknown): value is BuildRoute => value === 'template' || value === 'builder' || value === 'scratch' || value === 'copy_previous';
const isStep = (value: unknown): value is BuildStep => STEPS.includes(value as BuildStep);

const draftId = ref<string | undefined>(props.payload?.id ?? props.draft);
const data = ref<MesocycleShowOutput | undefined>(props.payload);
const failure = ref<ReadFailure | null>(null);
const build = computed(() => data.value?.build ?? null);

const read = screenRead<MesocycleShowOutput>(
    'mesocycle_show',
    'The mesocycle could not be loaded.',
    (fresh) => {
        failure.value = null;
        // A saved mesocycle has no build left: it is the mesocycle's own screen now.
        if (!fresh.build) void router.replace(`/train/mesocycles/${fresh.id}`);
        else data.value = fresh;
    },
    (why) => {
        data.value = undefined;
        failure.value = why;
    },
);

function load(options: { fresh?: boolean } = {}): Promise<void> {
    return draftId.value ? read({ mesocycle_id: draftId.value }, options) : Promise.resolve();
}

// A new draft's id, made once on the device so a retry cannot make a second (CAP-019).
let startId: string | null = null;
const newId = (): string => (startId ??= ulid());

// The step the user is on. It starts on the address's step, the template library for a
// template build with no draft yet, or where the draft was left, then follows the user.
const startsWithTemplate = !props.payload && !props.draft && props.route === 'template';
const chosen = ref<BuildStep | null>(isStep(props.step) ? props.step : startsWithTemplate ? 'template' : null);
watch(
    () => build.value?.resume_step,
    (resume) => {
        if (chosen.value === null && resume) chosen.value = resume;
    },
    { immediate: true },
);
const step = computed<BuildStep | null>(() => chosen.value);
const flowRoute = computed<BuildRoute>(() => (step.value && OWN_ROUTE[step.value]) || build.value?.route || (isRoute(props.route) ? props.route : 'builder'));

const templateId = ref<string | null>(null);
const nameText = ref<string | null>(props.payload?.name ?? null);

// A write that makes or adopts the draft: once the server has it, it is the draft the
// address names and the screen reads. After a write with no answer it reads nothing, as
// the draft may not exist; Try again sends the same write and the server replays it.
async function adopt(options: { fresh?: boolean } = {}): Promise<void> {
    if (options.fresh) return;
    draftId.value = newId();
    await router.replace({ query: { draft: draftId.value } });
    await load();
}

const starting = useWrite(adopt);
const editing = useWrite(load);
const busy = computed(() => starting.busy.value || editing.busy.value);
const writeError = computed(() => editing.error.value ?? starting.error.value);

const canStart = computed(() => !props.payload && !props.draft && (props.route === 'builder' || props.route === 'scratch' || (props.route === 'copy_previous' && !!props.copy)));

function create(): Promise<unknown> {
    const id = newId();
    if (props.route === 'copy_previous') {
        return starting.send('mesocycle_copy', { mesocycle_id: id, source_mesocycle_id: props.copy! } satisfies MesocycleCopyInput, 'The mesocycle could not be copied.');
    }

    return starting.send('mesocycle_build', { mesocycle_id: id, route: props.route as 'builder' | 'scratch' } satisfies MesocycleBuildInput, 'The mesocycle could not be started.');
}

if (canStart.value) void create();
onMounted(() => {
    if (draftId.value && !data.value) void load();
});

/** A change to the draft: one mesocycle_build write with the fields it changes. */
function change(fields: Omit<MesocycleBuildInput, 'mesocycle_id'>, message: string): Promise<unknown> {
    return editing.send('mesocycle_build', { mesocycle_id: draftId.value!, ...fields } satisfies MesocycleBuildInput, message);
}

function setPriority(muscle: PrioritySetInput['muscle'], priority: PrioritySetInput['priority']): void {
    void editing.send('priority_set', { mesocycle_id: draftId.value!, muscle, priority } satisfies PrioritySetInput, 'The priority could not be saved.');
}

function saveDays(days: BuildDaysInput): void {
    void change({ days }, 'The exercises could not be saved.');
}

async function generate(): Promise<void> {
    const outcome = await editing.send('mesocycle_structure_generate', { mesocycle_id: draftId.value! } satisfies MesocycleStructureGenerateInput, 'The sessions could not be built.');
    if (outcome === 'done') chosen.value = 'slots';
}

async function useTemplate(): Promise<void> {
    const outcome = await starting.send(
        'mesocycle_build',
        { mesocycle_id: draftId.value ?? newId(), route: 'template', template_id: templateId.value } satisfies MesocycleBuildInput,
        'The template could not be used.',
    );
    if (outcome === 'done') {
        templateId.value = null;
        chosen.value = 'slots';
    }
}

async function finish(): Promise<void> {
    const outcome = await change({ name: nameText.value ?? data.value?.name ?? '', finish: true }, 'The mesocycle could not be created.');
    if (outcome === 'done') void router.replace(`/train/mesocycles/${draftId.value}`);
}

function close(): void {
    if (window.history.state?.back) router.back();
    else void router.replace('/train/mesocycles');
}

function goNext(): void {
    switch (step.value) {
        case 'template':
            void useTemplate();
            break;
        case 'builder':
        case 'scratch':
            void generate();
            break;
        case 'slots':
            chosen.value = 'details';
            break;
        case 'details':
            void finish();
            break;
    }
}

function goBack(): void {
    if (step.value === 'details') chosen.value = 'slots';
    else if (step.value === 'slots') chosen.value = FIRST_STEP[flowRoute.value];
    else if (step.value === 'template') templateId.value = null;
}

const flow = computed<StepFlowStep>(() => {
    const current = step.value ?? 'builder';
    const base = { counter: COUNTERS[flowRoute.value][current] ?? '', title: TITLES[current], next_title: 'Continue', can_go_back: false, can_continue: true };
    if (current === 'template') {
        return { ...base, next_title: templateId.value ? 'Use this template' : 'Continue', can_go_back: templateId.value !== null, can_continue: templateId.value !== null, continue_hint: 'Pick a template to continue.' };
    }
    if (current === 'slots') return { ...base, can_go_back: flowRoute.value !== 'copy_previous' };
    if (current === 'details') {
        return { ...base, next_title: 'Create mesocycle', can_go_back: true, can_continue: build.value?.can_finish ?? false, continue_hint: 'Name it and start it in a phase to create it.' };
    }

    return base;
});

// The generic error line; the exercises step says its own, in the words of rp-clone.
const flowError = computed(() => (step.value === 'slots' ? null : writeError.value));
const startFailed = computed(() => canStart.value && !draftId.value && !starting.busy.value && starting.error.value !== null);
</script>

<template>
    <main class="bb-screen-content">
        <header class="mb-4 flex items-center">
            <button type="button" aria-label="Close" class="min-h-tap min-w-16 text-left text-accent" @click="close">Close</button>
            <h1 class="grow text-center text-base font-semibold">New mesocycle</h1>
            <span class="min-w-16" aria-hidden="true" />
        </header>

        <StepFlow v-if="step === 'template' || (step && build)" :step="flow" :disabled="busy" :pending="pending" @back="goBack" @next="goNext">
            <template v-if="step === 'template'">
                <TemplateDetailView v-if="templateId" :key="templateId" :template-id="templateId" embedded />
                <TemplateLibraryView v-else picking @open="(id) => (templateId = id)" />
            </template>
            <template v-else-if="build && data">
                <BuilderStepView v-if="step === 'builder'" :build="build" :priority-options="data.priority_options" :disabled="busy" @days="(days_per_week) => change({ days_per_week }, 'The days could not be saved.')" @priority="setPriority" />
                <ScratchStepView
                    v-else-if="step === 'scratch'"
                    :build="build"
                    :disabled="busy"
                    @days="(days_per_week) => change({ days_per_week }, 'The sessions could not be saved.')"
                    @label-style="(label_style) => change({ label_style }, 'The day labels could not be saved.')"
                />
                <SlotsStepView
                    v-else-if="step === 'slots'"
                    :build="build"
                    :error="writeError"
                    :disabled="busy"
                    :pending="pending"
                    @save="saveDays"
                    @pick="editing.send('mesocycle_slots_fill', { mesocycle_id: draftId! } satisfies MesocycleSlotsFillInput, 'The exercises could not be picked.')"
                />
                <DetailsStepView
                    v-else
                    :build="build"
                    :name="data.name ?? ''"
                    :disabled="busy"
                    @edit="(text) => (nameText = text)"
                    @save="(name) => change({ name }, 'The name could not be saved.')"
                    @weeks="(weeks) => change({ weeks }, 'The length could not be saved.')"
                    @start="(start_date) => change({ start_date }, 'The start date could not be saved.')"
                    @label-style="(label_style) => change({ label_style }, 'The day labels could not be saved.')"
                />
            </template>
            <p v-if="flowError" role="alert" class="mt-3 text-sm text-tone-warning">{{ flowError }}</p>
        </StepFlow>

        <div v-else-if="startFailed" class="space-y-3 rounded-2xl bg-surface p-4" role="alert">
            <p>{{ starting.error.value }}</p>
            <button type="button" class="min-h-tap rounded-xl border border-secondary-text/25 px-4 active:opacity-70" data-test="retry" @click="create">Try again</button>
        </div>
        <ReadState v-else-if="draftId || starting.busy.value" :failure="failure" what="The mesocycle" @retry="load" />
        <p v-else class="rounded-2xl bg-surface p-4 text-secondary-text">
            Nothing to build. <RouterLink to="/train/mesocycles" class="text-accent">Back to mesocycles</RouterLink>
        </p>
    </main>
</template>
