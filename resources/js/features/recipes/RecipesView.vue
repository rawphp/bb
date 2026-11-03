<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import ReadState from '@/app/ReadState.vue';
import { screenRead, type ReadFailure } from '@/app/screenRead';
import { onScreenReturn } from '@/app/screenTop';
import type { RecipesShowInput, RecipesShowOutput } from '@/contracts/recipes_show';
import ActionMenu, { type ActionMenuItem } from '@/design/ActionMenu.vue';
import BottomSheet from '@/design/BottomSheet.vue';
import PendingMark from '@/design/PendingMark.vue';
import PantryChip from '@/features/pantry/PantryChip.vue';
import SegmentedPicker from '@/design/SegmentedPicker.vue';
import { DIETS, MEAL_TYPES, SEGMENTS, type RecipeDiet, type RecipeMealType, type RecipeSegment } from './options';

// The Recipes screen (RCP-020), ported from rp-clone's RecipesView: My recipes and the
// Library, a meal-type chip row and diet filters the server applies, and the three ways to
// make a recipe (RCP-001, RCP-003, RCP-010). It opens with no diet filter on, and turning one
// on here does not touch the profile. The script holds only the choices the user has made;
// recipes_show sends the rows and their words. `pending` dims the server's macros (OFF-008).
const props = withDefaults(defineProps<{ payload?: RecipesShowOutput; pending?: boolean }>(), { payload: undefined, pending: false });

const router = useRouter();

const segment = ref<RecipeSegment>('mine');
const mealType = ref<RecipeMealType | null>(null);
const diets = ref<RecipeDiet[]>([]);
const dietsOpen = ref(false);

const data = ref<RecipesShowOutput | undefined>(props.payload);
const failure = ref<ReadFailure | null>(null);

const read = screenRead<RecipesShowOutput>(
    'recipes_show',
    'The recipes could not be loaded.',
    (fresh) => {
        failure.value = null;
        data.value = fresh;
    },
    (why) => {
        data.value = undefined;
        failure.value = why;
    },
);

function load(): Promise<void> {
    return read({ segment: segment.value, meal_type: mealType.value, diets: [...diets.value] } satisfies RecipesShowInput);
}

watch([segment, mealType, diets], () => void load());
onMounted(() => {
    if (!data.value) void load();
});
// Back from a recipe that was saved or deleted, the list reads again.
onScreenReturn(() => {
    if (props.payload === undefined) void load();
});

// Sent in DietFilter's order, whatever order they were turned on in.
function toggleDiet(value: RecipeDiet): void {
    const on = diets.value.includes(value) ? diets.value.filter((diet) => diet !== value) : [...diets.value, value];
    diets.value = DIETS.map((diet) => diet.value).filter((diet) => on.includes(diet));
}

const MENU: ActionMenuItem[] = [
    { key: '/nutrition/recipes/new', title: 'New recipe' },
    { key: '/nutrition/recipes/import', title: 'Import from URL' },
    { key: '/nutrition/recipes/generate', title: 'Generate with AI' },
];

const SEGMENT_OPTIONS = SEGMENTS.map((option) => ({ value: option.value, label: option.title }));

const dietTitle = computed(() => (diets.value.length === 0 ? 'Diet' : `Diet · ${diets.value.length}`));
</script>

<template>
    <main class="bb-screen-content flex flex-col gap-3">
        <header class="flex items-center justify-between gap-2">
            <h1 class="text-3xl font-bold">Recipes</h1>
            <ActionMenu label="Add a recipe" :items="MENU" @select="(path) => router.push(path)">
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-6 text-accent" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                    <path d="M12 5v14M5 12h14" />
                </svg>
            </ActionMenu>
        </header>

        <SegmentedPicker v-model="segment" label="List" :options="SEGMENT_OPTIONS" class="bg-secondary-text/15!" />

        <div class="-mx-4 flex items-center gap-2 overflow-x-auto px-4 [scrollbar-width:none]">
            <button
                type="button"
                data-test="diet-chip"
                class="shrink-0"
                aria-haspopup="dialog"
                :aria-label="diets.length === 0 ? 'Diet filters, none on' : `Diet filters, ${diets.length} on`"
                @click="dietsOpen = true"
            >
                <PantryChip :title="dietTitle" :active="diets.length > 0" />
            </button>
            <button
                v-for="option in [{ value: null, title: 'All' }, ...MEAL_TYPES]"
                :key="option.title"
                type="button"
                data-test="meal-type"
                :aria-pressed="mealType === option.value"
                class="inline-flex min-h-tap shrink-0 items-center rounded-full px-3 text-sm font-medium whitespace-nowrap"
                :class="mealType === option.value ? 'bg-accent text-on-accent' : 'bg-surface text-primary-text'"
                @click="mealType = option.value"
            >
                {{ option.title }}
            </button>
        </div>

        <template v-if="data">
            <ul class="divide-y divide-secondary-text/15">
                <li v-for="row in data.rows" :key="row.recipe_id" data-test="recipe-row">
                    <RouterLink :to="`/nutrition/recipes/${row.recipe_id}`" class="flex min-h-tap flex-col justify-center gap-0.5 py-2">
                        <span class="flex items-baseline gap-2">
                            <span class="min-w-0 flex-1 text-primary-text">{{ row.name }}</span>
                            <span v-if="row.meal_type_title" class="shrink-0 text-sm text-secondary-text">{{ row.meal_type_title }}</span>
                        </span>
                        <span class="text-sm text-secondary-text tabular-nums">
                            <PendingMark v-if="pending" derived>{{ row.macros_text }} per serving</PendingMark>
                            <template v-else>{{ row.macros_text }} per serving</template>
                        </span>
                    </RouterLink>
                </li>
            </ul>
            <p v-if="data.rows.length === 0 && data.empty_text" data-test="empty" class="text-secondary-text">{{ data.empty_text }}</p>
        </template>
        <ReadState v-else :failure="failure" what="Your recipes" @retry="load" />

        <BottomSheet :open="dietsOpen" title="Diet filters" :save-title="null" cancel-title="Done" :heights="['half', 'full']" @cancel="dietsOpen = false">
            <ul class="divide-y divide-secondary-text/15 px-4">
                <li v-for="diet in DIETS" :key="diet.value">
                    <button
                        type="button"
                        data-test="diet"
                        :aria-pressed="diets.includes(diet.value)"
                        class="flex min-h-tap w-full items-center justify-between gap-3 text-left"
                        @click="toggleDiet(diet.value)"
                    >
                        {{ diet.title }}
                        <svg v-if="diets.includes(diet.value)" aria-hidden="true" viewBox="0 0 24 24" class="size-5 text-accent" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m5 12 5 5 9-10" />
                        </svg>
                    </button>
                </li>
            </ul>
        </BottomSheet>
    </main>
</template>
