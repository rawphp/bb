<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { readFresh } from '@/app/call';
import { failureMessage } from '@/app/errors';
import { ulid } from '@/app/ulid';
import { useWrite, type WriteOutcome } from '@/app/useWrite';
import type { FoodBarcodeLookupOutput } from '@/contracts/food_barcode_lookup';
import type { MealFoodAddInput } from '@/contracts/meal_food_add';
import type { SavedMealSaveInput } from '@/contracts/saved_meal_save';
import type { ScanAdjustInput, ScanAdjustOutput } from '@/contracts/scan_adjust';
import type { ScanLabelInput, ScanLabelOutput } from '@/contracts/scan_label';
import type { ScanPhotoOutput } from '@/contracts/scan_photo';
import BottomSheet from '@/design/BottomSheet.vue';
import { toneIcons } from '@/design/toneIcons';
import { toneClasses } from '@/design/tones';
import SegmentedPicker from '@/features/today/sheets/SegmentedPicker.vue';
import ScanFoundCard from './ScanFoundCard.vue';
import ScanNotFoundCard from './ScanNotFoundCard.vue';
import ScanResultView from './ScanResultView.vue';
import { prepareImage, ReaderUnavailable, readBarcode, useCamera } from './useCamera';

// The scanner (FOOD-040 to FOOD-046), ported from rp-clone's ScanView.swift: the meal screen's camera button opens
// it over the meal. Barcode mode reads the rear camera live (PWA-031); label and photo modes take or choose a photo
// (PWA-032), and every mode works from an upload or typing on a device with no camera (PLT-003). The server reads
// and recalculates everything; this screen keeps only what is open and what was typed (VIEW-002).
// The custom food form and the Pantry belong to other screens, so the host puts them in the `custom-food` and
// `match` slots. `payload` opens the scanner in a fixture's state (/dev/screens).
type Mode = 'barcode' | 'label' | 'photo';
type Payload = FoodBarcodeLookupOutput | ScanPhotoOutput | ScanLabelOutput;
type Row = ScanAdjustInput['rows'][number];

const props = defineProps<{ mealId?: string; payload?: Payload }>();
const emit = defineEmits<{ close: [added: boolean] }>();
defineSlots<{
    /** FOOD-041, FOOD-042: the custom food form, with the barcode or the label's draft filled in. */
    'custom-food'?: (scope: { barcode: string | null; draft: ScanLabelOutput | null; saved: (foodId: string) => void; close: () => void }) => unknown;
    /** FOOD-043: the Pantry, searching the row's name, to pick the food it is matched to. */
    match?: (scope: { search: string; picked: (foodId: string) => void; close: () => void }) => unknown;
}>();

const MODES: { value: Mode; label: string }[] = [
    { value: 'barcode', label: 'Barcode' },
    { value: 'label', label: 'Nutrition label' },
    { value: 'photo', label: 'Photo' },
];

const mode = ref<Mode>('barcode');
const typedCode = ref('');
const description = ref('');
const lookup = ref<FoodBarcodeLookupOutput | null>(null);
// A barcode that found nothing, kept for the food the label scan or the form makes (FOOD-041).
const remembered = ref<string | null>(null);
const draft = ref<ScanPhotoOutput | null>(null);
const customFood = ref<{ barcode: string | null; draft: ScanLabelOutput | null } | null>(null);
const matching = ref<number | null>(null);
const naming = ref(false);
const mealName = ref('');
const saved = ref<string | null>(null);
const busyText = ref<string | null>(null);
// Counts scan_adjust answers, refused ones too: the list renders afresh, so no field keeps an amount the server
// did not take.
const adjusted = ref(0);
const error = ref<string | null>(null);
const preview = ref<string | null>(null);
const video = ref<HTMLVideoElement | null>(null);

const { access, live, start, stop } = useCamera();
const { send, error: writeError } = useWrite(async () => {});
const busy = computed(() => busyText.value !== null);
const NO_READER = "The barcode reader couldn't load. Type the code instead.";

// The ids of each create's last attempt (CAP-019), kept while a retry sends the same thing, so a retry after no
// answer replays that create; anything else gets new ids.
const lastIds = new Map<string, { sent: string; ids: string[] }>();
// The draft the saved meal's name was filled in for, so a retry keeps what was typed.
let namedFor: ScanPhotoOutput | null = null;

if (props.payload && 'status' in props.payload) showLookup(props.payload);
else if (props.payload && 'rows' in props.payload) showDraft(props.payload);
else if (props.payload) {
    mode.value = 'label';
    customFood.value = { barcode: props.payload.barcode ?? null, draft: props.payload };
}

watch(mode, (now) => {
    error.value = null;
    if (now !== 'barcode') stop();
});

onBeforeUnmount(() => showPreview(null));

/** One call at a time: the busy line says what it is doing, and a refusal shows the server's words. */
async function run<T>(text: string, call: () => Promise<T>, fallback: string): Promise<T | null> {
    error.value = null;
    busyText.value = text;
    try {
        return await call();
    } catch (failure) {
        error.value = failureMessage(failure, fallback);
        return null;
    } finally {
        busyText.value = null;
    }
}

/** A write, through useWrite (its idempotency key, CAP-014), with the same busy line and message as a read. */
async function save(text: string, name: string, input: Record<string, unknown>, fallback: string): Promise<WriteOutcome | null> {
    error.value = null;
    busyText.value = text;
    const outcome = await send(name, input, fallback);
    busyText.value = null;
    error.value = writeError.value;

    return outcome;
}

function idsFor(name: string, sent: unknown, count: number): string[] {
    const key = JSON.stringify(sent);
    const last = lastIds.get(name);
    if (last?.sent === key) return last.ids;
    const ids = Array.from({ length: count }, () => ulid());
    lastIds.set(name, { sent: key, ids });

    return ids;
}

function showPreview(url: string | null): void {
    if (preview.value?.startsWith('blob:')) URL.revokeObjectURL(preview.value);
    preview.value = url;
}

function showLookup(answer: FoodBarcodeLookupOutput): void {
    mode.value = 'barcode';
    lookup.value = answer;
    remembered.value = answer.status === 'not_found' ? answer.barcode : null;
}

function showDraft(next: ScanPhotoOutput | ScanAdjustOutput): void {
    mode.value = 'photo';
    draft.value = next;
    saved.value = null;
}

// FOOD-041

async function lookUp(code: string): Promise<void> {
    const barcode = code.trim();
    if (busy.value || barcode === '') return;
    lookup.value = null;
    const answer = await run('Looking it up', () => readFresh<FoodBarcodeLookupOutput>('food_barcode_lookup', { barcode }), 'The barcode could not be looked up.');
    if (answer) showLookup(answer);
}

async function scanLive(): Promise<void> {
    if (busy.value || !video.value) return;
    error.value = null;
    lookup.value = null;
    showPreview(null);
    try {
        await start(video.value, (code) => void lookUp(code));
    } catch (failure) {
        error.value = failure instanceof ReaderUnavailable ? NO_READER : "The camera couldn't start. Choose a photo or type the code.";
    }
}

function scanLabelInstead(): void {
    lookup.value = null;
    mode.value = 'label';
}

/** The meal gets these foods, raw, and the scanner closes. A food with no grams is fitted to the meal. */
async function addToMeal(foods: { food_id: string; grams?: number }[]): Promise<void> {
    if (busy.value || foods.length === 0) return;
    const ids = idsFor('meal_food_add', foods, foods.length);
    const input: MealFoodAddInput = {
        meal_id: props.mealId ?? '',
        // foods is not empty, so neither are the entries.
        entries: foods.map((food, index) => ({ id: ids[index]!, food_id: food.food_id, weighing: 'raw' as const, ...(food.grams === undefined ? {} : { grams: food.grams }) })) as MealFoodAddInput['entries'],
    };
    const outcome = await save('Adding to the meal', 'meal_food_add', { ...input }, 'The foods could not be added to the meal.');
    if (outcome === 'done') emit('close', true);
}

function customFoodSaved(foodId: string): void {
    customFood.value = null;
    remembered.value = null;
    void addToMeal([{ food_id: foodId }]);
}

// PWA-031, PWA-032: a photo taken or chosen, for the mode it was picked in.

async function photoPicked(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file || busy.value) return;
    const forMode = mode.value;
    error.value = null;

    if (forMode === 'barcode') {
        stop();
        lookup.value = null;
        showPreview(URL.createObjectURL(file));
        busyText.value = 'Reading the barcode';
        const code = await readBarcode(file).catch((failure: unknown) => (failure instanceof ReaderUnavailable ? failure : null));
        busyText.value = null;
        if (code instanceof ReaderUnavailable) error.value = NO_READER;
        else if (code === null) error.value = 'No barcode found in that photo. Try again or type the code.';
        else await lookUp(code);
        return;
    }

    busyText.value = 'Preparing the photo';
    const image = await prepareImage(file);
    busyText.value = null;
    if (image === null) {
        error.value = "Couldn't read that photo. Try another.";
        return;
    }
    showPreview(image);

    if (forMode === 'label') {
        const input: ScanLabelInput = { image, ...(remembered.value === null ? {} : { barcode: remembered.value }) };
        const label = await run('Reading the label', () => readFresh<ScanLabelOutput>('scan_label', { ...input }), 'The label could not be read.');
        if (label) customFood.value = { barcode: remembered.value, draft: label };
        return;
    }

    const estimate = await run('Estimating the foods', () => readFresh<ScanPhotoOutput>('scan_photo', { image }), 'The photo could not be read.');
    if (estimate) showDraft(estimate);
}

// FOOD-043 to FOOD-046

async function describe(): Promise<void> {
    const text = description.value.trim();
    if (busy.value || text === '') return;
    const estimate = await run('Estimating the foods', () => readFresh<ScanPhotoOutput>('scan_describe', { description: text }), 'The meal could not be estimated.');
    if (estimate) showDraft(estimate);
}

/** The rows as the server returned them, to send back with one change. */
function rowsAsSent(): Row[] {
    return (draft.value?.rows ?? []).map((row) => ({ name: row.name, grams: row.grams, base_grams: row.base_grams, food_id: row.food_id ?? null }));
}

async function adjust(rows: Row[], portion?: number): Promise<void> {
    const current = draft.value;
    if (busy.value || !current) return;
    const input: ScanAdjustInput = { rows, from_portion: current.portion, portion: portion ?? current.portion, saved_meal_name: current.saved_meal_name };
    const next = await run('Updating the list', () => readFresh<ScanAdjustOutput>('scan_adjust', { ...input }), 'The list could not be updated.');
    adjusted.value++;
    if (next) showDraft(next);
}

function setGrams(index: number, grams: number): void {
    const rows = rowsAsSent();
    const row = rows[index];
    if (!row) return;
    // A typed amount is at the current portion, so it goes without the row's base grams.
    rows[index] = { name: row.name, grams, food_id: row.food_id };
    void adjust(rows);
}

function removeRow(index: number): void {
    void adjust(rowsAsSent().filter((_, at) => at !== index));
}

function matched(foodId: string): void {
    const index = matching.value;
    matching.value = null;
    const rows = rowsAsSent();
    const row = index === null ? undefined : rows[index];
    if (!row || index === null) return;
    rows[index] = { ...row, food_id: foodId };
    void adjust(rows);
}

function beginSaving(): void {
    if (!draft.value) return;
    if (namedFor !== draft.value) mealName.value = draft.value.saved_meal_name;
    namedFor = draft.value;
    naming.value = true;
}

async function saveMeal(): Promise<void> {
    const sent = draft.value;
    const name = mealName.value.trim();
    if (busy.value || !sent || name === '') return;
    naming.value = false;
    const input: SavedMealSaveInput = { id: idsFor('saved_meal_save', { name, foods: sent.matched }, 1)[0]!, name, foods: sent.matched };
    const outcome = await save('Saving', 'saved_meal_save', { ...input }, 'The saved meal could not be saved.');
    // A list changed while the save was out is not what was saved.
    if (outcome === 'done' && draft.value === sent) saved.value = `Saved “${name}” to your saved meals.`;
}
</script>

<template>
    <BottomSheet :open="true" title="Scan a food" :save-title="null" :dismissible="!busy" :cancel-disabled="busy" @cancel="emit('close', false)">
        <div class="min-h-full space-y-4 bg-background p-4">
            <SegmentedPicker v-model="mode" label="Mode" :options="MODES" :disabled="busy" class="bg-secondary-text/15!" />

            <p class="text-sm text-secondary-text">
                <template v-if="mode === 'barcode'">{{
                    access === 'none' ? 'Choose a photo of a barcode, or type the code.' : 'Scan a barcode with the camera, choose a photo of one, or type the code.'
                }}</template>
                <template v-else-if="mode === 'label'">Take or choose a photo of the nutrition label. You check the food before it is saved.</template>
                <template v-else>Take or choose a photo of your plate, or describe it.</template>
            </p>

            <p v-if="mode === 'label' && remembered" class="text-xs text-secondary-text">Barcode {{ remembered }} is saved with the food.</p>

            <div v-if="mode === 'barcode'" v-show="live" class="relative aspect-4/3 overflow-hidden rounded-xl bg-black">
                <video ref="video" class="size-full object-cover" playsinline muted autoplay aria-label="Camera" data-test="viewfinder" />
                <div class="absolute inset-x-10 top-1/2 h-0.5 -translate-y-1/2 rounded-full bg-tone-warning" aria-hidden="true" />
            </div>
            <img v-if="preview && !live" :src="preview" alt="" class="mx-auto max-h-36 rounded-xl object-contain" />

            <div class="space-y-2">
                <div class="flex gap-2">
                    <template v-if="mode === 'barcode' && access === 'ready'">
                        <button
                            v-if="live"
                            type="button"
                            class="flex min-h-tap flex-1 items-center justify-center gap-2 rounded-full bg-accent font-semibold text-on-accent active:opacity-70"
                            @click="stop"
                        >
                            Stop camera
                        </button>
                        <button
                            v-else
                            type="button"
                            :disabled="busy"
                            class="flex min-h-tap flex-1 items-center justify-center gap-2 rounded-full bg-accent font-semibold text-on-accent active:opacity-70 disabled:opacity-50"
                            data-test="scan-live"
                            @click="scanLive"
                        >
                            <svg viewBox="0 0 24 24" class="size-5 fill-none stroke-current stroke-2" stroke-linecap="round" aria-hidden="true">
                                <path d="M4 8V6a2 2 0 0 1 2-2h2M16 4h2a2 2 0 0 1 2 2v2M20 16v2a2 2 0 0 1-2 2h-2M8 20H6a2 2 0 0 1-2-2v-2M8 9v6M11 9v6M14 9v6M17 9v6" />
                            </svg>
                            Scan barcode
                        </button>
                    </template>
                    <label
                        v-if="mode !== 'barcode' && access !== 'none'"
                        class="flex min-h-tap flex-1 cursor-pointer items-center justify-center gap-2 rounded-full bg-accent font-semibold text-on-accent active:opacity-70 has-disabled:opacity-50"
                    >
                        <svg viewBox="0 0 24 24" class="size-5 fill-none stroke-current stroke-2" stroke-linejoin="round" aria-hidden="true">
                            <path d="M4 8a2 2 0 0 1 2-2h2l1.5-2h5L16 6h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z" />
                            <circle cx="12" cy="12.5" r="3.5" />
                        </svg>
                        Take photo
                        <input type="file" accept="image/*" capture="environment" class="sr-only" :disabled="busy" data-test="take-photo" @change="photoPicked" />
                    </label>
                    <label
                        class="flex min-h-tap flex-1 cursor-pointer items-center justify-center gap-2 rounded-full bg-accent/15 font-semibold text-accent active:opacity-70 has-disabled:opacity-50"
                    >
                        <svg viewBox="0 0 24 24" class="size-5 fill-none stroke-current stroke-2" stroke-linejoin="round" aria-hidden="true">
                            <rect x="3" y="5" width="18" height="14" rx="2" />
                            <path d="m3 16 5-5 4 4 3-3 6 6" />
                            <circle cx="15.5" cy="9.5" r="1.5" />
                        </svg>
                        Choose photo
                        <input type="file" accept="image/*" class="sr-only" :disabled="busy" data-test="choose-photo" @change="photoPicked" />
                    </label>
                </div>
                <div v-if="mode === 'barcode' && access === 'denied'" class="rounded-xl p-2.5 text-sm" :class="toneClasses.info">
                    <p class="flex gap-2">
                        <svg viewBox="0 0 24 24" class="mt-0.5 size-4 shrink-0 fill-current" aria-hidden="true"><path :d="toneIcons.info" fill-rule="evenodd" /></svg>
                        Camera access is off. Allow the camera for this site in your browser's settings, or choose a photo instead.
                    </p>
                </div>
            </div>

            <template v-if="mode === 'barcode'">
                <form class="flex gap-2" @submit.prevent="lookUp(typedCode)">
                    <input
                        v-model="typedCode"
                        type="text"
                        inputmode="numeric"
                        autocomplete="off"
                        placeholder="Or type the code"
                        aria-label="Barcode"
                        class="min-h-tap min-w-0 flex-1 rounded-xl bg-surface px-3.5 text-primary-text"
                        data-test="typed-code"
                    />
                    <button
                        type="submit"
                        :disabled="busy || typedCode.trim() === ''"
                        class="min-h-tap rounded-full bg-accent px-4 font-semibold text-on-accent active:opacity-70 disabled:opacity-40"
                        data-test="look-up"
                    >
                        Look up
                    </button>
                </form>
                <ScanFoundCard v-if="lookup?.status === 'found' && lookup.food" :food="lookup.food" :disabled="busy" @add="addToMeal([{ food_id: lookup.food.food_id }])" />
                <ScanNotFoundCard
                    v-else-if="lookup?.status === 'not_found'"
                    :message="lookup.message ?? ''"
                    :disabled="busy"
                    @scan-label="scanLabelInstead"
                    @create="customFood = { barcode: lookup.barcode, draft: null }"
                />
            </template>

            <template v-if="mode === 'photo'">
                <form class="flex items-start gap-2" @submit.prevent="describe">
                    <textarea
                        v-model="description"
                        rows="1"
                        maxlength="1000"
                        placeholder="Or describe it"
                        aria-label="Describe the meal"
                        aria-description="For example, chicken burrito bowl with rice"
                        class="field-sizing-content max-h-28 min-h-tap min-w-0 flex-1 resize-none rounded-xl bg-surface px-3.5 py-3 text-primary-text"
                        data-test="describe"
                    />
                    <button
                        type="submit"
                        :disabled="busy || description.trim() === ''"
                        class="min-h-tap rounded-full bg-accent px-4 font-semibold text-on-accent active:opacity-70 disabled:opacity-40"
                        data-test="estimate"
                    >
                        Estimate
                    </button>
                </form>
                <ScanResultView
                    v-if="draft && draft.rows.length > 0"
                    :key="adjusted"
                    :draft="draft"
                    :disabled="busy"
                    :saved="saved"
                    @portion="(portion) => adjust(rowsAsSent(), portion)"
                    @grams="setGrams"
                    @match="matching = $event"
                    @remove="removeRow"
                    @add="addToMeal(draft.matched)"
                    @save="beginSaving"
                />
                <p v-else-if="draft?.empty_text" class="text-sm text-secondary-text">{{ draft.empty_text }}</p>
            </template>

            <p v-if="busyText" class="flex items-center gap-2 text-sm text-secondary-text" role="status" data-test="busy">
                <span class="size-4 animate-spin rounded-full border-2 border-secondary-text/30 border-t-secondary-text" aria-hidden="true" />{{ busyText }}
            </p>
            <p v-if="error" class="flex gap-2 text-sm text-tone-warning" role="alert" data-test="error">
                <svg viewBox="0 0 24 24" class="mt-0.5 size-4 shrink-0 fill-current" aria-hidden="true"><path :d="toneIcons.warning" fill-rule="evenodd" /></svg>{{ error }}
            </p>
        </div>

        <BottomSheet :open="naming" title="Name the saved meal" :heights="['half']" :save-disabled="mealName.trim() === ''" @cancel="naming = false" @save="saveMeal">
            <div class="min-h-full bg-background p-4">
                <input
                    v-model="mealName"
                    type="text"
                    maxlength="255"
                    aria-label="Name"
                    class="min-h-tap w-full rounded-xl bg-surface px-3.5 text-primary-text"
                    data-test="saved-meal-name"
                    @keydown.enter.prevent="saveMeal"
                />
            </div>
        </BottomSheet>

        <slot
            v-if="customFood"
            name="custom-food"
            :barcode="customFood.barcode"
            :draft="customFood.draft"
            :saved="customFoodSaved"
            :close="() => (customFood = null)"
        />
        <slot v-if="draft && matching !== null && draft.rows[matching]" name="match" :search="draft.rows[matching]!.name" :picked="matched" :close="() => (matching = null)" />
    </BottomSheet>
</template>
