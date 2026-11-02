<script setup lang="ts">
import { ref, watch } from 'vue';

// FOOD-043: a scanned row's grams, typed. A finished amount (Enter, or leaving the field) that is a number
// above zero and not what the row has goes to the server; anything else puts the row's grams back. ScanView
// renders the list afresh after every answer, so a refused amount does not stay.
const props = withDefaults(defineProps<{ grams: number; disabled?: boolean }>(), { disabled: false });
const emit = defineEmits<{ commit: [grams: number] }>();

const text = ref(String(props.grams));
watch(
    () => props.grams,
    (grams) => (text.value = String(grams)),
);

function done(): void {
    const typed = Number(text.value.trim().replace(',', '.'));
    if (text.value.trim() !== '' && Number.isFinite(typed) && typed > 0 && typed !== props.grams) {
        emit('commit', typed);
        return;
    }
    text.value = String(props.grams);
}
</script>

<template>
    <input
        v-model="text"
        type="text"
        inputmode="decimal"
        aria-label="Grams"
        :disabled="disabled"
        class="h-tap w-21 rounded-xl bg-background px-3 text-right text-primary-text disabled:opacity-50"
        data-test="grams"
        @change="done"
    />
</template>
