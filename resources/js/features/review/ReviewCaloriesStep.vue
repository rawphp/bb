<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { KEYPAD } from '@/app/keypad';
import type { ReviewShowOutput } from '@/contracts/review_show';
import ChoiceList, { type Choice } from '@/design/ChoiceList.vue';
import SegmentedPicker from '@/design/SegmentedPicker.vue';
import ToggleSwitch from '@/design/ToggleSwitch.vue';
import ReviewLine from './ReviewLine.vue';
import ReviewNote from './ReviewNote.vue';

type Calories = NonNullable<ReviewShowOutput['calories']>;

// Step 6 (REV-010 to REV-013, REV-050): last week's target and average, the server's
// reasons, and the options, each with next week's calories and macros. Choose my own
// shows its amount, Drop fatigue its deload question (REV-011), and a step plan offer
// may follow. Each pick is an answer the parent sends; nothing applies until Save
// (REV-031). `pending` dims the server's numbers (OFF-008).
const props = defineProps<{ calories: Calories; pending: boolean; busy: boolean }>();
const emit = defineEmits<{ option: [id: string]; own: [kcal: number]; deload: [too: boolean]; stepOffer: [accepted: boolean] }>();

const choices = computed<Choice<string>[]>(() =>
    props.calories.options.map((o) => ({ id: o.value, title: o.title, detail: o.detail || undefined, isRecommended: o.is_recommended, isEnabled: o.is_enabled })),
);
const selected = computed(() => props.calories.options.find((o) => o.is_selected)?.value ?? null);

// The text in the own-calories field, from the server's amount until the user types.
const ownText = ref('');
watch(
    () => props.calories.own?.value,
    (value) => (ownText.value = value == null ? '' : String(value)),
    { immediate: true },
);

function sendOwn(): void {
    const text = ownText.value.trim();
    if (/^\d+$/.test(text)) emit('own', Number(text));
}

const yesNo = [
    { value: 'yes', label: 'Yes' },
    { value: 'no', label: 'No' },
] as const;
const deloadAnswer = computed(() => (props.calories.deload?.answer ? 'yes' : 'no'));
</script>

<template>
    <div class="flex flex-col gap-3" data-test="step-calories">
        <ul data-test="lines" class="flex flex-col gap-3" :class="{ 'opacity-60': pending }">
            <ReviewLine v-for="line in calories.lines" :key="line.label" v-bind="line" />
        </ul>
        <ReviewNote v-for="note in calories.notes" :key="note.text" v-bind="note" />
        <ChoiceList
            label="Calories next week"
            :choices="choices"
            :model-value="selected"
            :disabled="busy"
            :pending="pending"
            data-test="calorie-options"
            @update:model-value="emit('option', $event)"
        />

        <label v-if="calories.own" data-test="own" class="flex min-h-tap items-center gap-2 rounded-xl bg-background px-3.5">
            <input
                v-model="ownText"
                type="text"
                v-bind="KEYPAD.steps"
                data-test="own-kcal"
                class="min-w-0 grow bg-transparent text-xl tabular-nums text-primary-text outline-none"
                :aria-label="calories.own.label"
                :placeholder="calories.own.label"
                :disabled="busy"
                @change="sendOwn"
            />
            <span class="text-secondary-text">{{ calories.own.unit_label }}</span>
        </label>

        <div v-if="calories.deload" data-test="deload" class="flex flex-col gap-2">
            <template v-if="calories.deload.kind === 'ask' && calories.deload.question">
                <h3 class="font-semibold text-primary-text">{{ calories.deload.question }}</h3>
                <SegmentedPicker :label="calories.deload.question" :options="[...yesNo]" :model-value="deloadAnswer" :disabled="busy" @update:model-value="emit('deload', $event === 'yes')" />
            </template>
            <ReviewNote v-if="calories.deload.note" :text="calories.deload.note" tone="info" />
            <p v-if="calories.deload.rir_label" data-test="rir" class="text-sm text-secondary-text">{{ calories.deload.rir_label }}</p>
        </div>

        <div v-if="calories.step_offer" data-test="step-offer-row" class="text-sm">
            <ToggleSwitch
                :label="calories.step_offer.label"
                data-test="step-offer"
                :model-value="calories.step_offer.accepted"
                :disabled="busy"
                @update:model-value="emit('stepOffer', $event)"
            />
        </div>
    </div>
</template>
