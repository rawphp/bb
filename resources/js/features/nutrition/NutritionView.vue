<script setup lang="ts">
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { isPending } from '@/app/outbox';
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';
import type { NutritionShowOutput } from '@/contracts/nutrition_show';
import type { WeekShowOutput } from '@/contracts/week_show';
import ItemCard from '@/design/ItemCard.vue';
import MacroBar from '@/design/MacroBar.vue';
import { toneIcons } from '@/design/toneIcons';
import { toneClasses } from '@/design/tones';
import WeekStrip from '@/design/WeekStrip.vue';

// The Nutrition tab (NAV-001, NAV-005): a day's meals, each opening the same meal screen Today
// opens (NAV-002), then links to the Pantry, Saved meals, Recipes and the Shopping list. The
// week strip (UI-001) walks the plan's days: `date` names the day, else the server's today, and
// picking another opens /nutrition/<date> in its place. Each meal is an item card (UI-002) whose
// body is its four macro bars (UI-003), planned food against target, all from the server.
// `pending` marks the server's numbers as waiting to sync (OFF-008).
const props = withDefaults(defineProps<{ payload?: NutritionShowOutput; week?: WeekShowOutput; date?: string; pending?: boolean }>(), {
    pending: false,
});

const router = useRouter();
const input = props.date ? { date: props.date } : {};
const { data, failure, load } = useRead<NutritionShowOutput>(
    'nutrition_show',
    input,
    props.date ? "This day's meals could not be loaded." : "Today's meals could not be loaded.",
    props.payload,
);
const { data: weekData, failure: weekFailure } = useRead<WeekShowOutput>('week_show', input, 'This week could not be loaded.', props.week);
const pending = computed(() => props.pending || isPending('nutrition_show'));
const weekPending = computed(() => props.pending || isPending('week_show'));

function go(date: string | null | undefined): void {
    if (date) void router.replace(`/nutrition/${date}`);
}

// Recipes and the Shopping list are V_RECIPES' and V_SHOP's screens, at these paths.
const LINKS = [
    { to: '/nutrition/pantry', title: 'Pantry' },
    { to: '/saved-meals', title: 'Saved meals' },
    { to: '/nutrition/recipes', title: 'Recipes' },
    { to: '/nutrition/shopping', title: 'Shopping list' },
] as const;
</script>

<template>
    <main class="bb-screen-content flex flex-col gap-4">
        <h1 class="px-1 text-3xl font-bold">Nutrition</h1>

        <WeekStrip
            :days="weekData?.days ?? []"
            label="Days of the week"
            :loading="!weekData"
            :error="weekFailure && !weekData ? weekFailure.error : null"
            :pending="weekPending"
            @select="go"
            @swipe="(step) => go(step > 0 ? weekData?.next_week : weekData?.previous_week)"
        />

        <section class="flex flex-col gap-3" aria-labelledby="nutrition-day">
            <h2 id="nutrition-day" class="px-1 text-lg font-semibold text-primary-text">{{ data?.date_text ?? (date ? 'Meals' : "Today's meals") }}</h2>
            <template v-if="data">
                <p v-if="data.empty_text && data.meals.length === 0" class="rounded-2xl bg-surface px-4 py-3 text-secondary-text" data-test="empty">
                    {{ data.empty_text }}
                </p>
                <ul v-else class="flex flex-col gap-3">
                    <li v-for="meal in data.meals" :key="meal.meal_id">
                        <RouterLink
                            :to="`/meals/${meal.meal_id}`"
                            class="block rounded-2xl transition-transform active:scale-[0.98] motion-reduce:transition-none motion-reduce:active:scale-100"
                            data-test="meal"
                        >
                            <ItemCard :title="meal.name" :time="meal.time_text ?? undefined" :pending="pending">
                                <template #icon>
                                    <!-- MEAL-047: the check-in state as an icon in its tone, its words for a screen reader. -->
                                    <svg
                                        viewBox="0 0 24 24"
                                        class="size-6 rounded-full"
                                        :class="toneClasses[meal.check_in.tone]"
                                        fill="currentColor"
                                        role="img"
                                        :aria-label="meal.check_in.text"
                                        data-test="check-in"
                                    >
                                        <path :d="toneIcons[meal.check_in.tone]" />
                                    </svg>
                                </template>
                                <div class="grid grid-cols-2 gap-x-4 gap-y-1.5" data-test="bars">
                                    <MacroBar
                                        v-for="bar in meal.bars"
                                        :key="bar.label"
                                        :bar="bar"
                                    />
                                </div>
                            </ItemCard>
                        </RouterLink>
                    </li>
                </ul>
            </template>
            <ReadState v-else :failure="failure" :what="date ? 'This day\'s meals' : 'Today\'s meals'" @retry="load" />
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
