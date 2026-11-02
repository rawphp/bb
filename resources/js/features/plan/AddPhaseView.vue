<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { findNavigation } from '@/app/navigation';
import ReadState from '@/app/ReadState.vue';
import { ulid } from '@/app/ulid';
import { useRead } from '@/app/useRead';
import { useWrite } from '@/app/useWrite';
import type { PhaseAddInput } from '@/contracts/phase_add';
import type { PlanShowOutput } from '@/contracts/plan_show';
import ChoiceList, { type Choice } from '@/design/ChoiceList.vue';
import PendingMark from '@/design/PendingMark.vue';
import { toneClasses } from '@/design/tones';

// Plan → Add a phase (PLAN-006): the type, the length and rate, what the mesocycles
// that fill it look like and where training starts. The server sends every option
// with its end date, warnings and mesocycles (`plan_show.add_phase`), so picking one
// only shows what it already sent. `pending` dims the server's dates while a write
// waits to sync (OFF-008). Ported from rp-clone's AddPhaseView.
const props = withDefaults(defineProps<{ payload?: PlanShowOutput; pending?: boolean }>(), { pending: false });

type PhaseType = PhaseAddInput['type'];

const router = useRouter();
// None outside the shell: dev screens and tests render this with a plain router.
const nav = findNavigation(router);
const { data, failure, load } = useRead<PlanShowOutput>('plan_show', {}, 'Your plan could not be loaded.', props.payload);
const { send, busy, error } = useWrite(load);

// The new phase's id, made once, so a retry after no answer is the same create (CAP-019).
const phaseId = ulid();
const picked = ref<PhaseType | null>(null);
// Index into the type's lengths and rates; null until the user picks, so the server's choice shows.
const lengthPick = ref<number | null>(null);
const ratePick = ref<number | null>(null);

const addPhase = computed(() => data.value?.add_phase);
const type = computed(() => addPhase.value?.types.find((t) => t.type === (picked.value ?? addPhase.value?.default_type)) ?? addPhase.value?.types[0]);
const lengthIndex = computed(() => lengthPick.value ?? Math.max(0, type.value?.lengths.findIndex((l) => l.is_selected) ?? 0));
const rateIndex = computed(() => ratePick.value ?? Math.max(0, type.value?.rates.findIndex((r) => r.is_selected) ?? 0));
const length = computed(() => type.value?.lengths[lengthIndex.value]);
const rate = computed(() => type.value?.rates[rateIndex.value]);

const typeChoices = computed<Choice<PhaseType>[]>(() => addPhase.value?.types.map((t) => ({ id: t.type, title: t.title, isRecommended: t.is_recommended })) ?? []);
const lengthChoices = computed<Choice<number>[]>(() => type.value?.lengths.map((l, index) => ({ id: index, title: l.label, detail: l.ends_text })) ?? []);
const rateChoices = computed<Choice<number>[]>(() => type.value?.rates.map((r, index) => ({ id: index, title: r.label, detail: r.rate.text })) ?? []);

// A new type starts from the server's length and rate for it.
watch(picked, () => {
    lengthPick.value = null;
    ratePick.value = null;
});

async function add(): Promise<void> {
    const chosen = type.value;
    if (!chosen || !length.value) return;
    const input = {
        phase_id: phaseId,
        type: chosen.type,
        weeks: length.value.weeks ?? null,
        rate_percent_per_week: rate.value?.rate.value ?? null,
    } satisfies PhaseAddInput;
    if ((await send('phase_add', input, 'The phase could not be added.')) === 'done') leave();
}

// The tab stack's back. Opened on its own (a deep link, /dev/screens) there is no
// screen under this one, so it goes to Train.
function leave(): void {
    if (nav && nav.state.stacks[nav.state.active].length > 1) nav.back();
    else void router.replace('/train');
}

const heading = 'px-1 text-sm font-medium text-secondary-text';
const dim = computed(() => (props.pending ? 'opacity-60' : ''));
</script>

<template>
    <main class="bb-screen-content space-y-6">
        <h1 class="text-center text-lg font-semibold">Add a phase</h1>

        <template v-if="addPhase && type">
            <section class="space-y-2" aria-labelledby="add-phase-type">
                <h2 id="add-phase-type" :class="heading">Type</h2>
                <ChoiceList label="Type" :choices="typeChoices" :model-value="type.type" :disabled="busy" data-test="types" @update:model-value="picked = $event" />
            </section>

            <section class="space-y-2" aria-labelledby="add-phase-length">
                <h2 id="add-phase-length" :class="heading">Length</h2>
                <p class="px-1 text-secondary-text" data-test="starts">
                    <PendingMark v-if="pending" derived>{{ addPhase.starts_text }}</PendingMark>
                    <template v-else>{{ addPhase.starts_text }}</template>
                </p>
                <ChoiceList
                    label="Length"
                    :choices="lengthChoices"
                    :model-value="lengthIndex"
                    :disabled="busy"
                    data-test="lengths"
                    :class="dim"
                    @update:model-value="lengthPick = $event"
                />
                <ChoiceList
                    v-if="rateChoices.length > 1"
                    label="Rate"
                    :choices="rateChoices"
                    :model-value="rateIndex"
                    :disabled="busy"
                    data-test="rates"
                    @update:model-value="ratePick = $event"
                />
                <p v-else-if="rate" class="flex min-h-tap items-center justify-between rounded-xl bg-surface px-4" data-test="rate">
                    <span>{{ rate.label }}</span>
                    <span class="text-secondary-text">{{ rate.rate.text }}</span>
                </p>
                <p v-if="addPhase.refusal" class="rounded-2xl p-4 text-sm" :class="toneClasses.warning" role="alert" data-test="refusal">{{ addPhase.refusal }}</p>
            </section>

            <section v-if="length?.warnings.length" class="space-y-2" aria-labelledby="add-phase-warnings">
                <h2 id="add-phase-warnings" :class="heading">Heads up</h2>
                <ul class="space-y-2" data-test="warnings">
                    <li v-for="warning in length.warnings" :key="warning.text" class="rounded-xl p-3 text-sm" :class="toneClasses[warning.tone]">{{ warning.text }}</li>
                </ul>
            </section>

            <section v-if="length?.mesocycles.length" class="space-y-2" aria-labelledby="add-phase-mesocycles">
                <h2 id="add-phase-mesocycles" :class="heading">Mesocycles that fill it</h2>
                <ul class="divide-y divide-secondary-text/15 rounded-xl bg-surface" data-test="mesocycles">
                    <li v-for="meso in length.mesocycles" :key="meso.label" class="flex min-h-tap items-center justify-between gap-3 px-4 py-2">
                        <span>{{ meso.label }}</span>
                        <span class="text-right text-sm text-secondary-text" :class="dim">{{ meso.weeks_text }} · {{ meso.dates_text }}</span>
                    </li>
                </ul>
                <template v-if="length.fixes.length">
                    <p class="px-1 text-sm">This ends part-way through a mesocycle.</p>
                    <ul class="space-y-1 px-1 text-sm text-secondary-text" data-test="fixes">
                        <li v-for="fix in length.fixes" :key="fix.fix">{{ fix.text }}</li>
                    </ul>
                </template>
                <p class="px-1 text-sm text-secondary-text">
                    {{
                        length.weeks === null || length.weeks === undefined
                            ? 'A phase with no end gets one mesocycle at a time. The next is proposed during the deload.'
                            : 'Each mesocycle ends on a deload week.'
                    }}
                </p>
            </section>

            <section v-if="type.training_lines.length" class="space-y-2" aria-labelledby="add-phase-training">
                <h2 id="add-phase-training" :class="heading">Training starts from</h2>
                <ul class="space-y-1 rounded-xl bg-surface px-4 py-3 text-sm" data-test="training">
                    <li v-for="line in type.training_lines" :key="line.text">{{ line.text }}</li>
                </ul>
            </section>

            <p v-if="error" class="rounded-2xl p-4" :class="toneClasses.warning" role="alert" data-test="error">{{ error }}</p>
            <button
                type="button"
                class="min-h-tap w-full rounded-2xl bg-accent font-semibold text-on-accent active:opacity-70 disabled:opacity-50"
                data-test="add"
                :disabled="busy || !!addPhase.refusal"
                :aria-busy="busy"
                @click="add"
            >
                {{ busy ? 'Adding…' : 'Add phase' }}
            </button>
        </template>

        <p v-else-if="data" class="text-center text-secondary-text" data-test="not-ready">Your plan is not ready yet.</p>
        <ReadState v-else :failure="failure" what="Your plan" @retry="load" />
    </main>
</template>
