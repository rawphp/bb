<script setup lang="ts">
import type { ReviewShowOutput } from '@/contracts/review_show';
import { checkInLook, checkMarks } from '@/design/checkInLook';
import ReviewNote from './ReviewNote.vue';

type Meals = NonNullable<ReviewShowOutput['meals']>;

// Step 2 (REV-003, REV-006): how many meals were checked in, then the week as one row per
// day with a mark for each meal, drawn like Today's check-in box. An open mark checks the
// meal in with one tap; a check-in is not removed from here. `pending` dims the server's
// count (OFF-008). A mark that turns into a check pops in; reduced motion fades it.
defineProps<{ meals: Meals; pending: boolean; busy: boolean }>();
const emit = defineEmits<{ checkIn: [mealId: string] }>();

const checked = checkInLook.on_track.color;
const open = checkInLook.not_checked_in.color;
</script>

<template>
    <div class="flex flex-col gap-3" data-test="step-meals">
        <ReviewNote :text="meals.message.text" :tone="meals.message.tone" :class="{ 'opacity-60': pending }" />
        <ul v-if="meals.days.length" class="flex flex-col divide-y divide-secondary-text/20">
            <li v-for="day in meals.days" :key="day.date" data-test="meal-day" class="flex items-center gap-2 py-1">
                <span data-test="day-label" class="flex w-10 shrink-0 flex-col items-center leading-tight">
                    <span class="text-xs font-semibold uppercase text-secondary-text">{{ day.weekday }}</span>
                    <span class="text-lg font-semibold tabular-nums text-primary-text">{{ day.day_number }}</span>
                </span>
                <span class="flex grow flex-wrap">
                    <!-- Only the swap animates: an open mark that comes back checked pops in. -->
                    <Transition
                        v-for="meal in day.meals"
                        :key="meal.meal_id"
                        enter-active-class="transition-transform duration-200 ease-[cubic-bezier(0.2,1.4,0.4,1)] motion-reduce:transition-opacity"
                        enter-from-class="scale-50 motion-reduce:scale-100 motion-reduce:opacity-0"
                    >
                        <span
                            v-if="meal.is_checked"
                            data-test="meal-mark"
                            data-checked="true"
                            role="img"
                            :aria-label="meal.label"
                            class="flex min-h-tap min-w-11 flex-1 basis-0 flex-col items-center justify-center gap-0.5"
                        >
                            <svg viewBox="0 0 24 24" class="size-7" :class="checked" aria-hidden="true">
                                <rect x="3.5" y="3.5" width="17" height="17" rx="3.5" stroke="currentColor" stroke-width="1.75" fill="currentColor" />
                                <path :d="checkMarks.check" class="stroke-on-accent" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            <span aria-hidden="true" class="max-w-full truncate text-xs text-secondary-text">{{ meal.name }}</span>
                        </span>
                        <button
                            v-else
                            type="button"
                            data-test="meal-mark"
                            data-checked="false"
                            :aria-label="meal.label"
                            :disabled="busy"
                            class="flex min-h-tap min-w-11 flex-1 basis-0 flex-col items-center justify-center gap-0.5 rounded-xl active:opacity-70 disabled:opacity-50"
                            @click="emit('checkIn', meal.meal_id)"
                        >
                            <svg viewBox="0 0 24 24" class="size-7" :class="open" aria-hidden="true">
                                <rect x="3.5" y="3.5" width="17" height="17" rx="3.5" stroke="currentColor" stroke-width="1.75" fill="none" />
                            </svg>
                            <span aria-hidden="true" class="max-w-full truncate text-xs font-medium text-accent">{{ meal.name }}</span>
                        </button>
                    </Transition>
                </span>
            </li>
        </ul>
    </div>
</template>
