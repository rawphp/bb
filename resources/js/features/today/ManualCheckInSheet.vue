<script setup lang="ts">
import { computed, ref } from 'vue';
import type { MealCheckInInput } from '@/contracts/meal_check_in';
import type { TodayShowOutput } from '@/contracts/today_show';
import BottomSheet from '@/design/BottomSheet.vue';
import TimeField from '@/design/TimeField.vue';
import { amount } from './sheets/amount';
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
// Each field with the server's number it opened on.
const fields = [
    { sent: manual.kcal, text: kcal },
    { sent: manual.protein_g, text: protein },
    { sent: manual.fat_g, text: fat },
    { sent: manual.carbs_g, text: carbs },
];

// A field left as it opened saves the server's number as sent; a typed one is read as
// typed (sheets/amount). An emptied field keeps Save off, as every amount opens filled in.
const input = computed<MealCheckInInput | null>(() => {
    const [k, p, f, c] = fields.map(({ sent, text }) => (text.value === String(sent) ? sent : text.value.trim() === '' ? null : amount(text.value)));
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
                <TimeField v-model="time" label="Time" name="time" required class="px-4" />
                <AmountRow v-model="kcal" :class="divider" title="Calories" unit="kcal" macro="calories" name="kcal" />
                <AmountRow v-model="protein" :class="divider" title="Protein" unit="g" macro="protein" name="protein" />
                <AmountRow v-model="fat" :class="divider" title="Fat" unit="g" macro="fat" name="fat" />
                <AmountRow v-model="carbs" :class="divider" title="Carbs" unit="g" macro="carbs" name="carbs" />
            </div>
        </fieldset>
    </BottomSheet>
</template>
