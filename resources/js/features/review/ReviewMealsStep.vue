<script setup lang="ts">
import type { ReviewShowOutput } from '@/contracts/review_show';
import CheckInBox from '@/design/CheckInBox.vue';
import { checkInLook } from '@/design/checkInLook';
import ReviewNote from './ReviewNote.vue';

type Meals = NonNullable<ReviewShowOutput['meals']>;

// Step 2 (REV-003, REV-006): how many meals were checked in, then the week as one row per
// day with a mark for each meal, Today's check-in box in the state the server sends, so an
// under or over meal shows as it does on Today (MEAL-047). An open mark checks the
// meal in with one tap; a check-in is not removed from here. `pending` dims the server's
// count (OFF-008). A mark that turns into a check pops in; reduced motion fades it.
defineProps<{ meals: Meals; pending: boolean; busy: boolean }>();
const emit = defineEmits<{ checkIn: [mealId: string] }>();
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
                            <CheckInBox :shape="checkInLook[meal.check_in].shape" :class="checkInLook[meal.check_in].color" />
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
                            <CheckInBox :shape="checkInLook[meal.check_in].shape" :class="checkInLook[meal.check_in].color" />
                            <span aria-hidden="true" class="max-w-full truncate text-xs font-medium text-accent">{{ meal.name }}</span>
                        </button>
                    </Transition>
                </span>
            </li>
        </ul>
    </div>
</template>
