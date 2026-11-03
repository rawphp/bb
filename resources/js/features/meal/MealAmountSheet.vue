<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { MealFoodAmountSetInput } from '@/contracts/meal_food_amount_set';
import type { MealShowOutput } from '@/contracts/meal_show';
import BottomSheet from '@/design/BottomSheet.vue';
import SegmentedPicker from '@/design/SegmentedPicker.vue';

// MEAL-004: type a food's amount in its weight unit or in its household unit ("slice").
// Ported from rp-clone's MealAmountSheet. The server converts the unit and rebalances the
// other unlocked foods; the sheet keeps only the unit picked and the text typed.
type Entry = MealShowOutput['entries'][number];
type Unit = MealFoodAmountSetInput['unit'];

const props = defineProps<{ entry: Entry | null; busy: boolean; error: string | null }>();

const emit = defineEmits<{ save: [value: number, unit: Unit]; cancel: [] }>();

const unit = ref<Unit>('g');
const text = ref('');

const units = computed(() => {
    const entry = props.entry;
    if (!entry) return [];
    const weight = { value: entry.amount_unit as Unit, label: entry.amount_unit };
    return entry.household_unit ? [weight, { value: 'household' as Unit, label: entry.household_unit }] : [weight];
});

// The field starts at the server's amount in its own unit; in the other unit it starts empty.
function startAt(to: Unit): void {
    text.value = props.entry && to === props.entry.amount_unit ? String(props.entry.amount) : '';
}

watch(
    () => props.entry,
    (entry) => {
        if (!entry) return;
        unit.value = entry.amount_unit as Unit;
        startAt(unit.value);
    },
    { immediate: true },
);
watch(unit, startAt);

// A comma is a decimal point on some keyboards. Blank or negative is not an amount.
const value = computed<number | null>(() => {
    const typed = text.value.trim().replace(',', '.');
    const parsed = typed === '' ? NaN : Number(typed);
    return Number.isFinite(parsed) && parsed >= 0 ? parsed : null;
});

function save(): void {
    if (value.value !== null) emit('save', value.value, unit.value);
}
</script>

<template>
    <BottomSheet
        :open="entry !== null"
        :title="entry?.name ?? ''"
        :heights="['half']"
        :save-disabled="value === null || busy"
        :cancel-disabled="busy"
        :dismissible="!busy"
        :error="error"
        @cancel="emit('cancel')"
        @save="save"
    >
        <form class="flex flex-col gap-3 p-4" @submit.prevent="save">
            <SegmentedPicker v-if="units.length > 1" v-model="unit" label="Unit" :options="units" :disabled="busy" />
            <label class="flex flex-col gap-1.5">
                <span class="sr-only">Amount in {{ unit === 'household' ? entry?.household_unit : unit }}</span>
                <input
                    v-model="text"
                    name="amount"
                    type="text"
                    inputmode="decimal"
                    autocomplete="off"
                    placeholder="Amount"
                    :disabled="busy"
                    class="min-h-tap w-full rounded-xl bg-background px-3.5 text-2xl text-primary-text tabular-nums"
                />
            </label>
            <p class="text-sm text-secondary-text">The other unlocked foods rebalance around it.</p>
        </form>
    </BottomSheet>
</template>
