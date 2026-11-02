<script setup lang="ts">
import { computed, ref } from 'vue';
import { now } from '@/app/clock';
import { ulid } from '@/app/ulid';
import type { OffPlanLogInput } from '@/contracts/off_plan_log';
import BottomSheet from '@/design/BottomSheet.vue';
import AmountRow from './AmountRow.vue';
import { useSheet, type SheetListeners } from './useSheet';

// DAY-001: "Save food macros", food eaten off-plan: an optional name, when it was eaten,
// and its calories and macros. It reads nothing; off_plan_log adds the entry to the day.
const props = defineProps<
    {
        /** The day the entry goes on. Opened on its own, the clock's today. */
        date?: string;
        /** 24-hour "HH:MM" the time starts at. Opened on its own, the clock's time. */
        time?: string;
    } & SheetListeners
>();

const two = (n: number) => String(n).padStart(2, '0');
const clock = now();
const day = props.date ?? `${clock.getFullYear()}-${two(clock.getMonth() + 1)}-${two(clock.getDate())}`;

// One id for the entry while the sheet is open, so a retry after no answer logs the same one (CAP-019).
const id = ulid();
const name = ref('');
const time = ref(props.time ?? `${two(clock.getHours())}:${two(clock.getMinutes())}`);
const kcal = ref('');
const protein = ref('');
const fat = ref('');
const carbs = ref('');

const { busy, error, locked, close, save } = useSheet(props);

/**
 * A typed amount as a number of zero or more; an empty field is 0, and anything else is none.
 * A comma is the decimal point too: it is the only one on a comma-decimal region's keypad.
 * Either mark followed by three digits could be a thousands separator ("1,200", "1.200"),
 * so that is none.
 */
function amount(text: string): number | null {
    if (/[.,]\d{3}$/.test(text.trim())) return null;
    const trimmed = text.trim().replace(',', '.');
    if (trimmed === '') return 0;
    return /^(\d+\.?\d*|\.\d+)$/.test(trimmed) ? Number(trimmed) : null;
}

const amounts = computed(() => ({ kcal: amount(kcal.value), protein_g: amount(protein.value), fat_g: amount(fat.value), carbs_g: amount(carbs.value) }));
const valid = computed(() => Object.values(amounts.value).every((value) => value !== null) && /^\d{2}:\d{2}$/.test(time.value));

function submit(): void {
    const { kcal, protein_g, fat_g, carbs_g } = amounts.value;
    if (!valid.value || kcal === null || protein_g === null || fat_g === null || carbs_g === null) return;
    const input: OffPlanLogInput = { id, date: day, time: time.value, name: name.value.trim() || null, kcal, protein_g, fat_g, carbs_g };
    void save('off_plan_log', { ...input }, 'The food could not be saved. Try again.');
}

const divider = 'border-t border-secondary-text/20';
</script>

<template>
    <BottomSheet
        :open="true"
        title="Save food macros"
        :heights="['half', 'full']"
        :save-disabled="!valid || busy"
        :cancel-disabled="busy"
        :dismissible="!busy"
        :error="error"
        @cancel="close"
        @save="submit"
    >
        <!-- Locked while a save that got no answer may have worked (CAP-014): the retry sends the same entry. -->
        <fieldset :disabled="locked" class="min-w-0 p-4">
            <div class="flex flex-col rounded-2xl bg-background">
                <input
                    v-model="name"
                    name="name"
                    placeholder="Name (optional)"
                    aria-label="Name (optional)"
                    class="min-h-tap bg-transparent px-4 py-2 outline-none placeholder:text-secondary-text/60"
                />
                <label :class="['flex min-h-tap items-center justify-between gap-2 px-4', divider]">
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
