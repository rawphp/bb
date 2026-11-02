<script setup lang="ts">
import { ref, watch } from 'vue';

// A decimal field with a unit. `modelValue` is the number in the unit on screen; the
// server converts and checks it. By default it emits every keystroke; with
// commitsWhileTyping off it emits when the field loses focus, for a value that moves
// other fields (the step 6 target). Ported from rp-clone's OnboardingParts.
const props = withDefaults(
    defineProps<{ label: string; unit: string; modelValue?: number | null; commitsWhileTyping?: boolean; disabled?: boolean }>(),
    { modelValue: null, commitsWhileTyping: true, disabled: false },
);

const emit = defineEmits<{ 'update:modelValue': [value: number | null] }>();

/** Up to 2 decimals without trailing zeros. */
function format(value: number | null): string {
    return value === null ? '' : String(Number(value.toFixed(2)));
}

/** What the user typed, as a number. A comma is a decimal point; anything else is no number. */
function parse(text: string): number | null {
    const trimmed = text.trim().replace(',', '.');
    const value = trimmed === '' ? NaN : Number(trimmed);
    return Number.isFinite(value) ? value : null;
}

const text = ref(format(props.modelValue));
const focused = ref(false);
// The value last given to the parent, or the parent's own once it changes. A parent that
// saves to a server holds the old value until the save lands, so a number put back to it
// meanwhile is still a change.
let sent = props.modelValue;

function push(): void {
    const parsed = parse(text.value);
    if (parsed === sent) return;
    sent = parsed;
    emit('update:modelValue', parsed);
}

function typed(): void {
    if (props.commitsWhileTyping) push();
}

// Tidies what was typed. A field left empty or unreadable shows the value again: the
// parent may hold its value until a save comes back, so props can lag the emit.
function left(): void {
    focused.value = false;
    push();
    text.value = format(parse(text.value) ?? props.modelValue);
}

// Another field moved this one (a unit switch, a length change). Never under the user's typing.
watch(
    () => props.modelValue,
    (value) => {
        sent = value;
        if (!focused.value && parse(text.value) !== value) text.value = format(value);
    },
);
</script>

<template>
    <div class="flex min-h-tap items-center gap-2 rounded-xl bg-background px-3.5" :class="{ 'opacity-50': disabled }">
        <input
            v-model="text"
            type="text"
            inputmode="decimal"
            autocomplete="off"
            :placeholder="label"
            :aria-label="`${label} in ${unit}`"
            :disabled="disabled"
            class="min-w-0 flex-1 bg-transparent py-2 tabular-nums text-primary-text outline-none placeholder:text-secondary-text/60"
            @input="typed"
            @focus="focused = true"
            @blur="left"
        />
        <span class="text-secondary-text" aria-hidden="true">{{ unit }}</span>
    </div>
</template>
