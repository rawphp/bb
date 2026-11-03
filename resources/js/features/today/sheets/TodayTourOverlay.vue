<script setup lang="ts">
import { computed, nextTick, onMounted, ref, useId } from 'vue';

// ONB-030: the 7-step coach-mark tour Today runs once after setup: the week strip, the
// day's totals, a meal card, a workout card, the weigh-in row, the menu and the tabs.
// Skip, or Done on the last step, ends it (`done`); the screen remembers that. The words
// are rp-clone's TodayTour, ported with its TodayTourOverlay.
const STEPS = [
    {
        title: 'Days and calories',
        text: 'Each day shows its calorie target and a dot once you hit it. Swipe for other weeks, tap a day to open it.',
        icon: 'M5 5h14v15H5zM5 9h14M9 3v4M15 3v4',
    },
    { title: "The day's totals", text: "Calories and protein eaten against today's target. Adjust moves calories between meals.", icon: 'M5 20V10M12 20V4M19 20v-7' },
    { title: 'Meals', text: 'Tap the box to check a meal in. Swipe left to edit or delete it, or press and hold for more.', icon: 'M7 3v8m-2-8v5a2 2 0 0 0 4 0V3m-2 8v10M17 3c-1.7 1-2.5 3-2.5 6s1 4 2.5 4v8' },
    { title: 'Workouts', text: 'Your session for the day, with its length and sets. Tap Start when you begin.', icon: 'M3 12h18M6 8v8M9 9v6M15 9v6M18 8v8' },
    { title: 'Weigh-ins', text: 'On your weigh-in days, log your weight here.', icon: 'M5 4h14l2 16H3zM9 9a3 3 0 0 1 6 0' },
    { title: 'The menu', text: 'Add meals, workouts and busy periods, or copy and edit your schedule.', icon: 'M6 12h.01M12 12h.01M18 12h.01' },
    { title: 'The tabs', text: 'Train, Nutrition, Progress and More are one tap away.', icon: 'M3 17h18v4H3zM6 19h.01M12 19h.01M18 19h.01' },
] as const;

const emit = defineEmits<{ done: [] }>();

const index = ref(0);
const step = computed(() => STEPS[index.value]!);
const last = computed(() => index.value === STEPS.length - 1);
const titleId = useId();
const nextButton = ref<HTMLButtonElement>();

async function next(): Promise<void> {
    if (last.value) {
        emit('done');
        return;
    }
    index.value++;
    await nextTick();
    nextButton.value?.focus();
}

onMounted(() => nextButton.value?.focus());
</script>

<template>
    <div class="fixed inset-0 z-40 flex items-end bg-scrim p-4 pb-24" role="dialog" aria-modal="true" :aria-labelledby="titleId" @keydown.esc="emit('done')">
        <div class="flex w-full flex-col gap-2.5 rounded-2xl bg-surface p-4 shadow-lg">
            <div class="flex items-center gap-2">
                <svg viewBox="0 0 24 24" class="size-6 text-accent" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path :d="step.icon" />
                </svg>
                <h2 :id="titleId" class="flex-1 text-base font-semibold text-primary-text">{{ step.title }}</h2>
                <span class="text-sm tabular-nums text-secondary-text">{{ index + 1 }} of {{ STEPS.length }}</span>
            </div>
            <p class="text-sm text-primary-text">{{ step.text }}</p>
            <div class="flex items-center justify-between">
                <button type="button" class="min-h-tap min-w-tap text-accent" data-test="skip" @click="emit('done')">Skip</button>
                <button ref="nextButton" type="button" class="min-h-tap min-w-tap rounded-xl bg-accent px-4 font-semibold text-on-accent" data-test="next" @click="next">
                    {{ last ? 'Done' : 'Next' }}
                </button>
            </div>
        </div>
    </div>
</template>
