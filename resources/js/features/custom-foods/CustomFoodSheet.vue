<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { ulid } from '@/app/ulid';
import { useWrite } from '@/app/useWrite';
import type { CustomFoodSaveInput } from '@/contracts/custom_food_save';
import type { CustomFoodsShowOutput } from '@/contracts/custom_foods_show';
import BottomSheet from '@/design/BottomSheet.vue';

// Create a custom food, or edit one (FOOD-060), ported from rp-clone's CustomFoodSheet.swift.
// The server checks every value and works out the calories the list shows; this form only
// turns what was typed into numbers (a comma counts as a decimal point).
type Food = CustomFoodsShowOutput['foods'][number];
type Unit = CustomFoodSaveInput['serving_unit'];

const props = withDefaults(
    defineProps<{
        open: boolean;
        /** The food to change; null creates a new one. */
        food?: Food | null;
        /** A new food's starting name, for example the search text that found nothing (FOOD-062). */
        name?: string;
        /** A new food's starting barcode, for example the code that found nothing. */
        barcode?: string;
    }>(),
    { food: null, name: '', barcode: '' },
);

const emit = defineEmits<{ cancel: []; saved: [id: string] }>();

const UNITS: { value: Unit; title: string }[] = [
    { value: 'g', title: 'Grams' },
    { value: 'cup', title: 'Cups' },
    { value: 'tbsp', title: 'Tablespoons' },
    { value: 'tsp', title: 'Teaspoons' },
    { value: 'piece', title: 'Pieces' },
];

const NUMBERS = [
    { key: 'serving_grams', label: 'Weight per serving (g)' },
    { key: 'protein', label: 'Protein (g)' },
    { key: 'fat', label: 'Fat (g)' },
    { key: 'carbs', label: 'Carbs (g)' },
    { key: 'fiber', label: 'Fiber (g)' },
] as const;

type NumberKey = 'serving_size' | (typeof NUMBERS)[number]['key'];

const id = ref('');
const name = ref('');
const brand = ref('');
const code = ref('');
const unit = ref<Unit>('g');
const numbers = ref<Record<NumberKey, string>>(blankNumbers());

const { send, busy, error } = useWrite(async () => {});

function blankNumbers(): Record<NumberKey, string> {
    return { serving_size: '', serving_grams: '', protein: '', fat: '', carbs: '', fiber: '' };
}

// Each opening starts from the food, or blank with a new id for a create (CAP-019).
function reset(): void {
    const food = props.food;
    id.value = food?.food_id ?? ulid();
    name.value = food?.name ?? props.name.trim();
    brand.value = food?.brand ?? '';
    code.value = food?.barcode ?? props.barcode;
    unit.value = food?.serving_unit ?? 'g';
    numbers.value = food
        ? {
              serving_size: String(food.serving_size),
              serving_grams: String(food.serving_grams),
              protein: String(food.protein),
              fat: String(food.fat),
              carbs: String(food.carbs),
              fiber: String(food.fiber),
          }
        : blankNumbers();
    error.value = null;
}

watch(
    () => props.open,
    (open) => open && reset(),
    { immediate: true },
);

/** What was typed as a number, or null when it is blank or not one. */
function typed(text: string): number | null {
    const cleaned = text.trim().replace(',', '.');
    const value = cleaned === '' ? NaN : Number(cleaned);

    return Number.isFinite(value) ? value : null;
}

const input = computed<CustomFoodSaveInput | null>(() => {
    const n = Object.fromEntries(Object.entries(numbers.value).map(([key, text]) => [key, typed(text)])) as Record<NumberKey, number | null>;
    if (name.value.trim() === '' || Object.values(n).some((value) => value === null)) return null;

    return {
        id: id.value,
        name: name.value.trim(),
        brand: brand.value.trim() || null,
        barcode: code.value.trim() || null,
        serving_size: n.serving_size!,
        serving_unit: unit.value,
        serving_grams: n.serving_grams!,
        protein: n.protein!,
        fat: n.fat!,
        carbs: n.carbs!,
        fiber: n.fiber!,
    };
});

async function save(): Promise<void> {
    if (!input.value) return;
    const saving = input.value;
    if ((await send('custom_food_save', { ...saving }, 'The food could not be saved. Try again.')) === 'done') emit('saved', saving.id);
}

const fieldClass = 'min-h-tap w-full rounded-xl bg-background px-3.5 text-base text-primary-text placeholder:text-secondary-text';
</script>

<template>
    <BottomSheet
        :open="open"
        :title="food ? 'Edit custom food' : 'New custom food'"
        :save-disabled="input === null || busy"
        :cancel-disabled="busy"
        :dismissible="!busy"
        :error="error"
        @cancel="emit('cancel')"
        @save="save"
    >
        <form class="flex flex-col gap-4 p-4" @submit.prevent="save">
            <label class="flex flex-col gap-1.5">
                <span class="text-sm text-secondary-text">Name</span>
                <input v-model="name" name="name" type="text" autocapitalize="sentences" autocomplete="off" placeholder="Name" :disabled="busy" :class="fieldClass" />
            </label>
            <label class="flex flex-col gap-1.5">
                <span class="text-sm text-secondary-text">Brand (optional)</span>
                <input v-model="brand" name="brand" type="text" autocapitalize="words" autocomplete="off" placeholder="Brand (optional)" :disabled="busy" :class="fieldClass" />
            </label>
            <label class="flex flex-col gap-1.5">
                <span class="text-sm text-secondary-text">Barcode (optional)</span>
                <input v-model="code" name="barcode" type="text" inputmode="numeric" autocomplete="off" placeholder="Barcode (optional)" :disabled="busy" :class="fieldClass" />
            </label>
            <div class="flex flex-col gap-1.5">
                <span class="text-sm text-secondary-text" aria-hidden="true">Serving size and unit</span>
                <div class="flex items-center gap-2">
                    <input
                        v-model="numbers.serving_size"
                        name="serving_size"
                        type="text"
                        inputmode="decimal"
                        autocomplete="off"
                        placeholder="Serving size"
                        aria-label="Serving size"
                        :disabled="busy"
                        :class="fieldClass"
                    />
                    <select
                        v-model="unit"
                        name="serving_unit"
                        aria-label="Unit"
                        :disabled="busy"
                        class="min-h-tap shrink-0 rounded-xl bg-transparent px-2 text-base text-accent"
                    >
                        <option v-for="choice in UNITS" :key="choice.value" :value="choice.value">{{ choice.title }}</option>
                    </select>
                </div>
            </div>
            <template v-for="(item, index) in NUMBERS" :key="item.key">
                <h3 v-if="index === 1" class="pt-1 font-semibold text-primary-text">Nutrition per serving</h3>
                <label class="flex flex-col gap-1.5">
                    <span class="text-sm text-secondary-text">{{ item.label }}</span>
                    <input
                        v-model="numbers[item.key]"
                        :name="item.key"
                        type="text"
                        inputmode="decimal"
                        autocomplete="off"
                        :placeholder="item.label"
                        :disabled="busy"
                        :class="fieldClass"
                    />
                </label>
            </template>
        </form>
    </BottomSheet>
</template>
