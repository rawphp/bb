<script setup lang="ts">
import { computed, ref } from 'vue';
import type { MealCheckInInput } from '@/contracts/meal_check_in';
import type { TodayShowOutput } from '@/contracts/today_show';
import BottomSheet from '@/design/BottomSheet.vue';
import AmountRow from './sheets/AmountRow.vue';

// MEAL-041: when the meal was eaten, and its calories and macros. It opens on what the
// server sends for the meal (check_in.manual) and saves the amounts as typed; the screen
// sends them (meal_check_in). Ported from rp-clone's ManualCheckInSheet.
const props = withDefaults(defineProps<{ meal: TodayShowOutput['meals'][number]; busy?: boolean; error?: string | null }>(), { busy: false, error: null });

const emit = defineEmits<{ cancel: []; save: [input: MealCheckInInput] }>();

const manual = props.meal.check_in.manual;
const time = ref(manual.time);
const kcal = ref(String(manual.kcal));
const protein = ref(String(manual.protein_g));
const fat = ref(String(manual.fat_g));
const carbs = ref(String(manual.carbs_g));

// A typed amount as a number of zero or more; null while it is not one.
function amount(text: string): number | null {
    const value = Number(text.trim().replace(',', '.'));
    return text.trim() !== '' && Number.isFinite(value) && value >= 0 ? value : null;
}

const input = computed<MealCheckInInput | null>(() => {
    const [k, p, f, c] = [kcal, protein, fat, carbs].map((field) => amount(field.value));
    if (k == null || p == null || f == null || c == null || !time.value) return null;
    return { meal_id: props.meal.id, time: time.value, kcal: k, protein_g: p, fat_g: f, carbs_g: c };
});

function save(): void {
    if (input.value) emit('save', input.value);
}

const divider = 'border-t border-secondary-text/15';
</script>

<template>
    <BottomSheet
        :open="true"
        :title="`Check in ${meal.name}`"
        :heights="['half', 'full']"
        :save-disabled="!input || busy"
        :cancel-disabled="busy"
        :dismissible="!busy"
        :error="error"
        @cancel="emit('cancel')"
        @save="save"
    >
        <fieldset :disabled="busy" class="min-w-0 p-4">
            <div class="flex flex-col rounded-2xl bg-background">
                <label class="flex min-h-tap items-center justify-between gap-2 px-4">
                    <span>Time</span>
                    <input v-model="time" name="time" type="time" required class="rounded-lg bg-secondary-text/15 px-3 py-1.5 tabular-nums" />
                </label>
                <AmountRow v-model="kcal" :class="divider" title="Calories" unit="kcal" macro="calories" name="kcal" />
                <AmountRow v-model="protein" :class="divider" title="Protein" unit="g" macro="protein" name="protein" />
                <AmountRow v-model="fat" :class="divider" title="Fat" unit="g" macro="fat" name="fat" />
                <AmountRow v-model="carbs" :class="divider" title="Carbs" unit="g" macro="carbs" name="carbs" />
            </div>
        </fieldset>
    </BottomSheet>
</template>
