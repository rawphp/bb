<script setup lang="ts">
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';
import type { NutritionShowOutput } from '@/contracts/nutrition_show';
import PendingMark from '@/design/PendingMark.vue';
import { toneIcons } from '@/design/toneIcons';
import { toneClasses } from '@/design/tones';

// The Nutrition tab (NAV-001): today's meals, each opening the same meal screen Today opens
// (NAV-002), then links to the Pantry, Saved meals, Recipes and the Shopping list. Ported from
// rp-clone's NutritionView. `pending` marks the server's calories as waiting to sync (OFF-008).
const props = withDefaults(defineProps<{ payload?: NutritionShowOutput; pending?: boolean }>(), { pending: false });

const { data, failure, load } = useRead<NutritionShowOutput>('nutrition_show', {}, "Today's meals could not be loaded.", props.payload);

// Recipes and the Shopping list are V_RECIPES' and V_SHOP's screens, at these paths.
const LINKS = [
    { to: '/nutrition/pantry', title: 'Pantry' },
    { to: '/saved-meals', title: 'Saved meals' },
    { to: '/nutrition/recipes', title: 'Recipes' },
    { to: '/nutrition/shopping', title: 'Shopping list' },
] as const;
</script>

<template>
    <main class="bb-screen-content">
        <h1 class="mb-4 px-1 text-3xl font-bold">Nutrition</h1>

        <section class="mb-6" aria-labelledby="todays-meals">
            <h2 id="todays-meals" class="px-1 pb-1.5 text-lg font-semibold text-primary-text">Today's meals</h2>
            <template v-if="data">
                <p v-if="data.empty_text && data.meals.length === 0" class="rounded-2xl bg-surface px-4 py-3 text-secondary-text" data-test="empty">{{ data.empty_text }}</p>
                <ul v-else class="divide-y divide-secondary-text/15 overflow-hidden rounded-2xl bg-surface">
                    <li v-for="meal in data.meals" :key="meal.meal_id">
                        <RouterLink :to="`/meals/${meal.meal_id}`" class="flex min-h-tap items-center gap-2.5 px-4 py-2" data-test="meal">
                            <span class="min-w-0 flex-1">
                                <span class="block font-semibold text-primary-text">{{ meal.name }}</span>
                                <span v-if="meal.time_text" class="block text-sm text-secondary-text">{{ meal.time_text }}</span>
                            </span>
                            <span class="text-right text-sm text-secondary-text tabular-nums">
                                <PendingMark v-if="pending" derived>{{ meal.kcal_text }}</PendingMark>
                                <template v-else>{{ meal.kcal_text }}</template>
                            </span>
                            <!-- MEAL-047: the check-in state as an icon in its tone, its words for a screen reader. -->
                            <svg
                                viewBox="0 0 24 24"
                                class="size-5 shrink-0 rounded-full"
                                :class="toneClasses[meal.check_in.tone]"
                                fill="currentColor"
                                role="img"
                                :aria-label="meal.check_in.text"
                                data-test="check-in"
                            >
                                <path :d="toneIcons[meal.check_in.tone]" />
                            </svg>
                        </RouterLink>
                    </li>
                </ul>
            </template>
            <ReadState v-else :failure="failure" what="Today's meals" @retry="load" />
        </section>

        <nav class="divide-y divide-secondary-text/15 overflow-hidden rounded-2xl bg-surface" aria-label="Nutrition">
            <RouterLink v-for="link in LINKS" :key="link.to" :to="link.to" class="flex min-h-tap items-center gap-2 px-4 text-primary-text">
                <span class="flex-1">{{ link.title }}</span>
                <svg viewBox="0 0 24 24" class="size-4 text-secondary-text" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="m9 5 7 7-7 7" />
                </svg>
            </RouterLink>
        </nav>
    </main>
</template>
