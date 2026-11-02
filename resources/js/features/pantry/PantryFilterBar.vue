<script setup lang="ts">
import { computed, ref } from 'vue';
import type { FoodSearchOutput } from '@/contracts/food_search';
import BottomSheet from '@/design/BottomSheet.vue';
import PantryChip from './PantryChip.vue';
import { COLLECTIONS, GROUPS, SOURCES, UNITS, type PantryCollection, type PantryGroup, type PantrySource, type PantryUnit } from './options';

// The Pantry's filters as chips (FOOD-009, FOOD-020, FOOD-025 to FOOD-027), ported from
// rp-clone's PantryFilterBar. Single choices are native selects under their chip, so the
// device's own picker opens. The diet filters are toggles in a sheet; the server keeps
// them on the profile (FOOD-027) and sends which are on.
const props = withDefaults(
    defineProps<{
        collection: PantryCollection | null;
        source: PantrySource | null;
        group: PantryGroup | null;
        unit: PantryUnit;
        diets: FoodSearchOutput['diets'];
        /** A diet save is running: the toggles wait for it. */
        dietSaving?: boolean;
        dietError?: string | null;
        /** Off when picking a recipe's foods: no Recipes or Saved Meals. */
        showsCollections?: boolean;
    }>(),
    { dietSaving: false, dietError: null, showsCollections: true },
);

const emit = defineEmits<{
    'update:collection': [value: PantryCollection | null];
    'update:source': [value: PantrySource | null];
    'update:group': [value: PantryGroup | null];
    'update:unit': [value: PantryUnit];
    diet: [key: FoodSearchOutput['diets'][number]['key']];
}>();

const dietsOpen = ref(false);
const dietsOn = computed(() => props.diets.filter((diet) => diet.is_on).length);

function titleOf(options: readonly { value: string; title: string }[], value: string | null, fallback: string): string {
    return options.find((option) => option.value === value)?.title ?? fallback;
}

// A select's empty value is "none".
function picked<T extends string>(event: Event): T | null {
    return ((event.target as HTMLSelectElement).value || null) as T | null;
}
</script>

<template>
    <div class="-mx-4 flex items-center gap-2 overflow-x-auto px-4 [scrollbar-width:none]">
        <label v-if="showsCollections" class="relative shrink-0">
            <PantryChip :title="titleOf(COLLECTIONS, collection, 'Foods')" :active="collection !== null" />
            <select
                aria-label="Show"
                class="absolute inset-0 cursor-pointer opacity-0"
                :value="collection ?? ''"
                @change="emit('update:collection', picked($event))"
            >
                <option value="">Foods</option>
                <option v-for="option in COLLECTIONS" :key="option.value" :value="option.value">{{ option.title }}</option>
            </select>
        </label>
        <template v-if="collection === null">
            <label class="relative shrink-0">
                <PantryChip data-test="source-chip" :title="titleOf(SOURCES, source, 'Source')" :active="source !== null" />
                <select
                    aria-label="Source filter"
                    class="absolute inset-0 cursor-pointer opacity-0"
                    :value="source ?? ''"
                    @change="emit('update:source', picked($event))"
                >
                    <option value="">All sources</option>
                    <option v-for="option in SOURCES" :key="option.value" :value="option.value">{{ option.title }}</option>
                </select>
            </label>
            <label class="relative shrink-0">
                <PantryChip data-test="group-chip" :title="titleOf(GROUPS, group, 'Group')" :active="group !== null" />
                <select
                    aria-label="Group filter"
                    class="absolute inset-0 cursor-pointer opacity-0"
                    :value="group ?? ''"
                    @change="emit('update:group', picked($event))"
                >
                    <option value="">All groups</option>
                    <option v-for="option in GROUPS" :key="option.value" :value="option.value">{{ option.title }}</option>
                </select>
            </label>
            <button
                type="button"
                data-test="diet-chip"
                class="shrink-0"
                aria-haspopup="dialog"
                :aria-label="dietsOn === 0 ? 'Diet filters, none on' : `Diet filters, ${dietsOn} on`"
                @click="dietsOpen = true"
            >
                <PantryChip :title="dietsOn === 0 ? 'Diet' : `Diet · ${dietsOn}`" :active="dietsOn > 0" />
            </button>
            <div role="group" aria-label="Unit" class="flex shrink-0 rounded-full bg-surface p-0.5">
                <button
                    v-for="option in UNITS"
                    :key="option"
                    type="button"
                    :data-test="`unit-${option}`"
                    :aria-pressed="unit === option"
                    class="min-h-[calc(var(--spacing-tap)_-_4px)] min-w-12 rounded-full px-3 text-sm font-medium"
                    :class="unit === option ? 'bg-background text-primary-text shadow-sm' : 'text-secondary-text'"
                    @click="emit('update:unit', option)"
                >
                    {{ option }}
                </button>
            </div>
        </template>

        <BottomSheet :open="dietsOpen" title="Diet filters" :save-title="null" cancel-title="Done" :heights="['half', 'full']" :error="dietError" @cancel="dietsOpen = false">
            <ul class="divide-y divide-secondary-text/15 px-4">
                <li v-for="diet in diets" :key="diet.key">
                    <button
                        type="button"
                        data-test="diet"
                        :aria-pressed="diet.is_on"
                        :disabled="dietSaving"
                        class="flex min-h-tap w-full items-center justify-between gap-3 text-left disabled:opacity-60"
                        @click="emit('diet', diet.key)"
                    >
                        {{ diet.title }}
                        <svg v-if="diet.is_on" aria-hidden="true" viewBox="0 0 24 24" class="size-5 text-accent" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m5 12 5 5 9-10" />
                        </svg>
                    </button>
                </li>
            </ul>
        </BottomSheet>
    </div>
</template>
