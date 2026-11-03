<script setup lang="ts">
import { ref } from 'vue';
import type { ReviewShowOutput } from '@/contracts/review_show';
import ReviewMoveSheet, { type ReviewMove } from './ReviewMoveSheet.vue';

type Preview = NonNullable<ReviewShowOutput['preview']>;
type Day = Preview['days'][number];

// Step 7 (REV-003, REV-004): next week's days with type, workout and calories. Tapping
// one opens the move sheet; `move` sends the move and resolves null to close the sheet
// (the week took it, or no final answer came and the re-read week shows), or why the
// move did not go, which the sheet shows. `pending` dims the server's numbers (OFF-008).
const props = defineProps<{ preview: Preview; pending: boolean; busy: boolean; move: (move: ReviewMove) => Promise<string | null> }>();

// The day whose sheet is open, and why its last move did not go through.
const moving = ref<Day | null>(null);
const refusal = ref<string | null>(null);

function open(day: Day | null): void {
    moving.value = day;
    refusal.value = null;
}

async function send(move: ReviewMove): Promise<void> {
    refusal.value = await props.move(move);
    if (refusal.value === null) moving.value = null;
}
</script>

<template>
    <div class="flex flex-col gap-2" data-test="step-preview">
        <button
            v-for="day in preview.days"
            :key="day.index"
            type="button"
            data-test="day"
            class="flex min-h-tap w-full items-center gap-2.5 rounded-[10px] bg-background px-3 py-1 text-left active:opacity-70"
            :disabled="!day.can_move"
            :aria-description="day.can_move ? 'Moves calories to or from this day' : undefined"
            @click="open(day)"
        >
            <span class="flex grow flex-col gap-0.5">
                <span :class="day.is_locked ? 'text-secondary-text' : 'text-primary-text'">{{ day.date_label }}</span>
                <span class="text-sm text-secondary-text">{{ day.detail }}</span>
            </span>
            <template v-if="day.kcal_label">
                <span data-test="kcal" class="tabular-nums text-primary-text" :class="{ 'opacity-60': pending }">{{ day.kcal_label }}</span>
                <svg v-if="day.is_locked" data-test="locked" aria-hidden="true" viewBox="0 0 24 24" class="size-4 shrink-0 text-secondary-text" fill="currentColor">
                    <path d="M7 10V7a5 5 0 0 1 10 0v3h1a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-9a1 1 0 0 1 1-1zm2 0h6V7a3 3 0 0 0-6 0z" />
                </svg>
                <svg v-else aria-hidden="true" viewBox="0 0 24 24" class="size-4 shrink-0 text-secondary-text" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 8h15l-4-4M20 16H5l4 4" />
                </svg>
            </template>
        </button>
        <p v-if="preview.hint" data-test="hint" class="text-sm text-secondary-text">{{ preview.hint }}</p>
        <ReviewMoveSheet v-if="moving" :day="moving" :preview="preview" :busy="busy" :error="refusal" @cancel="open(null)" @move="send" />
    </div>
</template>
