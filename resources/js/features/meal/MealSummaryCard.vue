<script setup lang="ts">
import type { MealShowOutput } from '@/contracts/meal_show';
import ProgressBar from '@/design/ProgressBar.vue';
import StatusPill from '@/design/StatusPill.vue';
import type { CheckInState } from '@/design/checkInLook';
import { toneText } from '@/design/toneText';
import { toneClasses } from '@/design/tones';
import CheckInButton from '@/features/today/CheckInButton.vue';

// MEAL-001, MEAL-003, MEAL-010, MEAL-011, MEAL-040, MEAL-047: the four macro bars, the
// Targets met badge, the calorie and protein pills, the peri-workout tag and the check-in
// box (Today's CheckInButton). Ported from rp-clone's MealSummaryCard. The server sends every text and tone.
// `checkable` is false when the menu offers neither Check in meal nor Remove check-in.
// `pending` marks the server's numbers as waiting to sync (OFF-008).
defineProps<{ meal: MealShowOutput; checkable: boolean; busy: boolean; pending: boolean }>();

const emit = defineEmits<{ check: [] }>();

// The bar's macro colour and its spoken name, by the server's short label.
// meal_show names the check-in states in camelCase; the box takes today_show's names.
const CHECK_IN_STATES: Record<MealShowOutput['check_in']['state'], CheckInState> = {
    notCheckedIn: 'not_checked_in',
    onTrack: 'on_track',
    under: 'under',
    over: 'over',
};

const MACROS: Record<string, { title: string; tint: 'calories' | 'protein' | 'fat' | 'carbs' }> = {
    Cal: { title: 'Calories', tint: 'calories' },
    P: { title: 'Protein', tint: 'protein' },
    F: { title: 'Fat', tint: 'fat' },
    C: { title: 'Carbs', tint: 'carbs' },
};
</script>

<template>
    <section class="flex flex-col gap-2.5 rounded-2xl bg-surface p-3.5" aria-label="Targets" data-test="summary">
        <div class="flex items-center gap-2">
            <span v-if="meal.time_text" class="text-sm text-secondary-text">{{ meal.time_text }}</span>
            <span class="flex-1" />
            <StatusPill v-if="meal.targets_met" :text="meal.targets_met.text" :tone="meal.targets_met.tone" :pending="pending" />
            <StatusPill v-if="meal.adjust_badge" :text="meal.adjust_badge.text" :tone="meal.adjust_badge.tone" :pending="pending" />
            <!-- MEAL-040, MEAL-044: the box checks the meal in at its planned foods, or removes the check-in. -->
            <CheckInButton
                :state="CHECK_IN_STATES[meal.check_in.state]"
                :label="meal.check_in.text"
                :meal-name="meal.title"
                :disabled="!checkable || busy"
                data-test="check"
                @toggle="emit('check')"
            />
        </div>
        <p v-if="meal.check_in.state !== 'notCheckedIn'" class="text-sm font-semibold" :class="toneText[meal.check_in.tone]" data-test="check-in-text">
            {{ meal.check_in.text }}
        </p>
        <ProgressBar
            v-for="bar in meal.bars"
            :key="bar.label"
            :title="MACROS[bar.label]?.title ?? bar.label"
            :label="bar.label"
            :value-text="bar.value_text"
            :fraction="bar.fraction"
            :tone="bar.tone"
            :tint="MACROS[bar.label]?.tint ?? 'accent'"
            :pending="pending"
        />
        <div class="flex flex-wrap gap-2">
            <StatusPill v-for="pill in meal.pills" :key="pill.text" :text="pill.text" :tone="pill.tone" :pending="pending" />
        </div>
        <div v-if="meal.peri_workout" class="rounded-xl p-2.5" :class="toneClasses.info" data-test="peri-workout">
            <p class="flex items-center gap-1.5 text-sm font-semibold">
                <svg viewBox="0 0 24 24" class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="M3 12h18M6 8v8M9 9v6M15 9v6M18 8v8" />
                </svg>
                {{ meal.peri_workout.title }} · {{ meal.peri_workout.detail }}
            </p>
            <p class="text-sm text-secondary-text">{{ meal.peri_workout.help }}</p>
        </div>
    </section>
</template>
