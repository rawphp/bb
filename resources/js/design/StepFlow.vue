<script lang="ts">
/** One mark on a step track (ONB-022), as `onboarding_show` sends it. */
export interface StepFlowMark {
    step: number;
    label: string;
    state: 'done' | 'current' | 'todo';
    /** The mark opens its step (a done step): StepFlow emits `open` with it. */
    can_open: boolean;
}

/**
 * One step as the server sends it, field for field (`onboarding_show`'s `step`),
 * so a screen passes the payload straight in: `<StepFlow :step="data.step">`.
 */
export interface StepFlowStep {
    /** "Step 2 of 4", or the server's text for a screen after the last step. */
    counter: string;
    title: string;
    /** "Next", or "Done" on the last step. */
    next_title: string;
    can_go_back: boolean;
    can_continue: boolean;
    /** Why Next is off, such as "Pick a goal to continue." */
    continue_hint?: string | null;
    /** The whole route, for a flow that sends one (ONB-022). Without it the flow has no track. */
    track?: StepFlowMark[] | null;
}
</script>

<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import BottomSheet from './BottomSheet.vue';
import StepFlowTrack from './StepFlowTrack.vue';

// Step counter, title, content, Back and Next (UI-007). Ported from rp-clone's
// StepFlow.swift. The server sends the counter, the Next title and whether the
// step can go back or continue (CAP-001), so the flow does no maths.
// VIEW-004 states take the same props as ItemCard and BottomSheet. A flow that sends a
// track (ONB-022) also gets its route: on a phone a segmented bar under the counter, whose
// All steps opens the list in a sheet; on a wide screen the list beside the step. A done
// mark the server lets open emits `open` with its step; the flow decides what that does.
const props = withDefaults(
    defineProps<{
        step: StepFlowStep;
        /** The step is still loading: "Loading" shows in place of the content. */
        loading?: boolean;
        /** The step failed to load: this message shows in place of the content. */
        error?: string | null;
        /** A change is waiting to sync (OFF-008). The step stays usable. */
        pending?: boolean;
        disabled?: boolean;
    }>(),
    { loading: false, error: null, pending: false, disabled: false },
);

const emit = defineEmits<{ back: []; next: []; open: [step: number] }>();

const titleId = useId();
const hintId = useId();
const blocked = computed(() => props.disabled || props.loading);
const backOff = computed(() => blocked.value || !props.step.can_go_back);
const nextOff = computed(() => blocked.value || !!props.error || !props.step.can_continue);
const hint = computed(() => (!blocked.value && !props.error && !props.step.can_continue ? props.step.continue_hint : null));

const track = computed(() => props.step.track ?? null);
const sheetOpen = ref(false);

function openFromSheet(step: number): void {
    sheetOpen.value = false;
    emit('open', step);
}

const button = 'flex min-h-tap flex-1 items-center justify-center rounded-xl px-4 font-semibold disabled:opacity-50';
</script>

<template>
    <div
        role="group"
        :aria-labelledby="titleId"
        :aria-busy="props.loading || undefined"
        :aria-disabled="props.disabled || undefined"
        class="rounded-2xl bg-surface p-4"
        :class="{ 'opacity-50': props.disabled, 'md:grid md:grid-cols-[12rem_minmax(0,1fr)] md:items-start md:gap-6': track }"
    >
        <nav v-if="track" data-track-rail aria-label="Steps" class="hidden md:-ml-2 md:block md:self-stretch md:border-r md:border-secondary-text/15 md:pr-4">
            <StepFlowTrack :marks="track" :disabled="blocked" @open="emit('open', $event)" />
        </nav>
        <div class="flex min-w-0 flex-col gap-4">
            <div v-if="track" class="flex flex-col gap-2">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-sm text-secondary-text">{{ props.step.counter }}</p>
                    <button
                        type="button"
                        data-track-all
                        class="-my-3 flex min-h-tap items-center gap-1 text-sm font-semibold text-accent active:opacity-70 md:hidden"
                        @click="sheetOpen = true"
                    >
                        All steps
                    </button>
                </div>
                <div data-track-bar class="flex gap-1 md:hidden" aria-hidden="true">
                    <span
                        v-for="mark in track"
                        :key="`${mark.step}-${mark.state}`"
                        :data-mark="mark.state"
                        class="h-1.5 flex-1 overflow-hidden rounded-full"
                        :class="[mark.state === 'done' ? 'bg-accent' : 'bg-accent/15', { 'flex-[3]': mark.state === 'current' }]"
                    >
                        <Transition
                            appear
                            enter-active-class="transition-transform duration-300 ease-out motion-reduce:transition-opacity"
                            enter-from-class="scale-x-0 motion-reduce:scale-x-100 motion-reduce:opacity-0"
                        >
                            <span v-if="mark.state === 'current'" data-fill class="block h-full origin-left rounded-full bg-accent" />
                        </Transition>
                    </span>
                </div>
            </div>
            <p v-else class="text-sm text-secondary-text">{{ props.step.counter }}</p>
            <h2 :id="titleId" class="text-2xl font-bold text-primary-text">{{ props.step.title }}</h2>
            <p v-if="props.loading" class="animate-pulse text-secondary-text">Loading</p>
            <p v-else-if="props.error" role="alert" class="text-tone-warning">{{ props.error }}</p>
            <div v-else-if="$slots.default" class="w-full">
                <slot />
            </div>
            <p v-if="props.pending && !props.loading" role="status" class="text-sm text-secondary-text">Waiting to sync</p>
            <p v-if="hint" :id="hintId" class="text-sm text-secondary-text">{{ hint }}</p>
            <div class="flex gap-3">
                <button
                    type="button"
                    data-step="back"
                    :class="[button, 'bg-accent/15 text-accent']"
                    :disabled="backOff"
                    @click="emit('back')"
                >
                    Back
                </button>
                <button
                    type="button"
                    data-step="next"
                    :class="[button, 'bg-accent text-on-accent']"
                    :disabled="nextOff"
                    :aria-describedby="hint ? hintId : undefined"
                    @click="emit('next')"
                >
                    {{ props.step.next_title }}
                </button>
            </div>
        </div>
        <BottomSheet v-if="track" :open="sheetOpen" title="Steps" :save-title="null" cancel-title="Close" :heights="['half', 'full']" @cancel="sheetOpen = false">
            <StepFlowTrack class="px-2 pb-4" :marks="track" :disabled="blocked" @open="openFromSheet" />
        </BottomSheet>
    </div>
</template>
