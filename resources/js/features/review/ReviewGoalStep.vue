<script setup lang="ts">
import type { ReviewShowOutput } from '@/contracts/review_show';
import ReviewNote from './ReviewNote.vue';

type Goal = NonNullable<ReviewShowOutput['goal']>;
type Option = Goal['options'][number];

// Step 5 (REV-003, PH-041): keep the goal, the server's pick, or choose a new one or end
// the plan, which open the phase transition (PH-030). Plain buttons, as rp-clone's are:
// a radio group's arrow keys would pick, and picking here leaves the review.
defineProps<{ goal: Goal }>();
const emit = defineEmits<{ transition: [] }>();

function choose(option: Option): void {
    if (!option.is_selected) emit('transition');
}
</script>

<template>
    <div class="flex flex-col gap-3" data-test="step-goal">
        <ReviewNote v-for="note in goal.notes" :key="note.text" v-bind="note" />
        <button
            v-for="option in goal.options"
            :key="option.id"
            type="button"
            data-test="goal-option"
            class="flex min-h-tap w-full items-center gap-3 rounded-xl border bg-surface px-3.5 py-1.5 text-left active:opacity-70 disabled:opacity-60"
            :class="option.is_selected ? 'border-accent ring-1 ring-inset ring-accent' : 'border-secondary-text/25'"
            :aria-label="option.is_recommended ? `${option.title}, recommended` : option.title"
            :aria-pressed="option.is_selected"
            :disabled="!option.is_enabled"
            @click="choose(option)"
        >
            <svg aria-hidden="true" viewBox="0 0 24 24" class="size-6 shrink-0" :class="option.is_selected ? 'text-accent' : 'text-secondary-text'" fill="none" stroke="currentColor" stroke-width="1.75">
                <template v-if="option.is_selected">
                    <circle cx="12" cy="12" r="10" fill="currentColor" stroke="none" />
                    <path d="M7.5 12.5l3 3 6-6.5" class="stroke-on-accent" stroke-width="2.25" />
                </template>
                <circle v-else cx="12" cy="12" r="9" />
            </svg>
            <span class="flex min-w-0 grow flex-col gap-0.5">
                <span class="text-base text-primary-text">{{ option.title }}</span>
                <span v-if="option.detail" class="whitespace-pre-line text-sm text-secondary-text">{{ option.detail }}</span>
            </span>
            <span v-if="option.is_recommended" class="ml-2 shrink-0 rounded-full border border-accent px-2 py-0.5 text-xs font-semibold text-accent">Recommended</span>
        </button>
    </div>
</template>
