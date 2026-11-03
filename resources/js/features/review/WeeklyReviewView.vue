<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { findNavigation } from '@/app/navigation';
import { isPending } from '@/app/outbox';
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';
import { useWrite, type WriteOutcome } from '@/app/useWrite';
import type { ReviewAnswerInput } from '@/contracts/review_answer';
import type { ReviewShowOutput } from '@/contracts/review_show';
import StepFlow, { type StepFlowStep } from '@/design/StepFlow.vue';
import ReviewCaloriesStep from './ReviewCaloriesStep.vue';
import ReviewGoalStep from './ReviewGoalStep.vue';
import ReviewMealsStep from './ReviewMealsStep.vue';
import type { ReviewMove } from './ReviewMoveSheet.vue';
import ReviewPreviewStep from './ReviewPreviewStep.vue';
import ReviewProgressStep from './ReviewProgressStep.vue';
import ReviewTrainingStep from './ReviewTrainingStep.vue';
import ReviewWeighInsStep from './ReviewWeighInsStep.vue';

// The weekly review (REV-001 to REV-050): the steps review_show sends, each with Back and
// Next, and Save on the last. The server sends every number, judgement and option; the
// screen holds only which step is open, what is typed, and the calorie moves the user
// made (review_answer takes the answer whole, REV-004). Each answer goes with
// review_answer and Save with review_complete; nothing applies before Save (REV-031).
// `step` opens a named step, for links and /dev/screens. rp-clone's WeeklyReviewView.
const props = withDefaults(defineProps<{ payload?: ReviewShowOutput; pending?: boolean; step?: string }>(), { pending: false, step: undefined });

const router = useRouter();
const { data, failure, load } = useRead<ReviewShowOutput>('review_show', {}, 'Your week could not be loaded.', props.payload);
const { send, busy, error } = useWrite(load);
const pending = computed(() => props.pending || isPending('review_show'));

// The open step, by key, so a step list that changes keeps the user where they are.
const at = ref<string | undefined>(props.step);
const steps = computed(() => data.value?.steps ?? []);
const index = computed(() => Math.max(0, steps.value.findIndex((s) => s.key === at.value)));
const current = computed(() => steps.value[index.value]);
const isLast = computed(() => index.value === steps.value.length - 1);

const flow = computed<StepFlowStep>(() => ({
    counter: `Step ${index.value + 1} of ${steps.value.length}`,
    title: current.value?.title ?? '',
    next_title: isLast.value ? (busy.value ? 'Saving' : (data.value?.save_title ?? 'Save')) : 'Next',
    can_go_back: index.value > 0,
    can_continue: !isLast.value || (!!data.value?.can_save && !busy.value),
}));

// The calorie moves the server has taken, sent whole with every answer.
const moves = ref<ReviewMove[]>([]);
// Why the week refused the last move, kept while the answer goes back without it.
const moveError = ref<string | null>(null);

const message = computed(() => error.value ?? (isLast.value ? (data.value?.preview?.error ?? null) : null));

function leave(): void {
    const nav = findNavigation(router);
    if (nav && nav.state.stacks[nav.state.active].length > 1) nav.back();
    else void router.replace('/today');
}

function go(by: 1 | -1): void {
    at.value = steps.value[index.value + by]?.key ?? at.value;
}

async function next(): Promise<void> {
    if (!isLast.value) return go(1);
    const week = data.value?.week_start;
    if (week && (await send('review_complete', { week_start: week }, 'The review could not be saved.')) === 'done') leave();
}

/** The whole answer as the screen shows it, with `change` over it. */
async function answer(change: Partial<ReviewAnswerInput>): Promise<WriteOutcome | null> {
    const shown = data.value;
    if (!shown?.week_start) return null;
    const calories = shown.calories;
    const input = {
        week_start: shown.week_start,
        option: (calories?.options.find((o) => o.is_selected)?.id ?? 'recommendation') as ReviewAnswerInput['option'],
        own_kcal: calories?.own?.value ?? null,
        deload_too: calories?.deload?.kind === 'ask' ? (calories.deload.answer ?? null) : null,
        update_step_plan: calories?.step_offer?.accepted ?? false,
        moves: moves.value,
        ...change,
    } satisfies ReviewAnswerInput;

    return send('review_answer', input, 'Your answer could not be saved.');
}

function pickOption(option: string): void {
    void answer({ option: option as ReviewAnswerInput['option'], own_kcal: null, deload_too: null });
}

/** REV-004: sends the moves with this one; true once the week took it. */
async function move(next: ReviewMove): Promise<boolean> {
    moveError.value = null;
    const withMove = [...moves.value, next];
    if ((await answer({ moves: withMove })) !== 'done') return false;
    const refused = data.value?.preview?.error;
    if (!refused) {
        moves.value = withMove;
        return true;
    }
    moveError.value = refused;
    await answer({});
    return false;
}

function addWeighIn(at?: string): void {
    void router.push(at ? { path: '/weigh-ins/log', query: { at } } : { path: '/weigh-ins/log' });
}

function checkIn(mealId: string): void {
    void send('meal_check_in', { meal_id: mealId }, 'The meal could not be checked in.');
}
</script>

<template>
    <main class="bb-screen-content mx-auto flex w-full max-w-[560px] flex-col gap-4">
        <header class="relative flex min-h-tap items-center justify-center">
            <button type="button" data-test="close" class="absolute left-0 min-h-tap px-1 text-accent active:opacity-70" @click="leave">Close</button>
            <h1 class="text-base font-semibold">Weekly review</h1>
        </header>

        <ReadState v-if="!data" :failure="failure" what="Your weekly review" @retry="load" />
        <p v-else-if="data.state === 'unavailable' || !current" data-test="unavailable" class="px-6 py-12 text-center text-primary-text">{{ data.message }}</p>
        <StepFlow v-else :key="current.key" :step="flow" :pending="pending" @back="go(-1)" @next="next">
            <div class="flex flex-col gap-4">
                <p data-test="week" class="text-sm font-semibold text-secondary-text">{{ data.week_label }}</p>
                <ReviewWeighInsStep v-if="current.key === 'weigh_ins' && data.weigh_ins" :weigh-ins="data.weigh_ins" @add="addWeighIn" />
                <ReviewMealsStep v-else-if="current.key === 'meals' && data.meals" :meals="data.meals" :busy="busy" @check-in="checkIn" />
                <ReviewTrainingStep v-else-if="current.key === 'training' && data.training" :training="data.training" :pending="pending" />
                <ReviewProgressStep v-else-if="current.key === 'progress' && data.progress" :progress="data.progress" :pending="pending" />
                <ReviewGoalStep v-else-if="current.key === 'goal' && data.goal" :goal="data.goal" @transition="router.push('/train/plan/transition')" />
                <ReviewCaloriesStep
                    v-else-if="current.key === 'calories' && data.calories"
                    :calories="data.calories"
                    :pending="pending"
                    :busy="busy"
                    @option="pickOption"
                    @own="(kcal) => answer({ own_kcal: kcal })"
                    @deload="(too) => answer({ deload_too: too })"
                    @step-offer="(accepted) => answer({ update_step_plan: accepted })"
                />
                <ReviewPreviewStep
                    v-else-if="current.key === 'preview' && data.preview"
                    :preview="data.preview"
                    :pending="pending"
                    :busy="busy"
                    :error="moveError ?? error"
                    :move="move"
                />
                <p v-if="message" role="alert" data-test="message" class="flex items-start gap-2 text-sm text-tone-warning">{{ message }}</p>
            </div>
        </StepFlow>
    </main>
</template>
