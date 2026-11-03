<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useWrite } from '@/app/useWrite';
import type { StepsLogInput } from '@/contracts/steps_log';
import type { WeighInsShowOutput } from '@/contracts/weigh_ins_show';
import BottomSheet from '@/design/BottomSheet.vue';

// A day's step count typed by hand (WT-020), for the Weigh-ins screen's steps list. No
// rp-clone view: its steps came from Health. A new count starts on the day the server
// gives (yesterday); the server keeps a typed count over the Shortcuts bridge's.
type StepRow = WeighInsShowOutput['steps']['rows'][number];

const props = withDefaults(
    defineProps<{
        open: boolean;
        defaultDate: string;
        reload: (options?: { fresh?: boolean }) => Promise<void>;
        /** The day to change; null logs a new count. */
        row?: StepRow | null;
    }>(),
    { row: null },
);

const emit = defineEmits<{ cancel: []; done: [] }>();

const date = ref('');
const steps = ref('');
/** The opening was for a day's count: its day stays, as steps_log changes the day it is sent. */
const editing = ref(false);

const { send, busy, error } = useWrite((options) => props.reload(options));

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        editing.value = props.row !== null;
        date.value = props.row?.date ?? props.defaultDate;
        steps.value = props.row ? String(props.row.steps) : '';
        error.value = null;
    },
    { immediate: true },
);

const input = computed<StepsLogInput | null>(() => {
    const count = steps.value.trim();
    if (!/^\d+$/.test(count) || !/^\d{4}-\d{2}-\d{2}$/.test(date.value)) return null;
    return { date: date.value, steps: Number(count) };
});

async function save(): Promise<void> {
    if (input.value && (await send('steps_log', { ...input.value }, 'The steps could not be saved. Try again.')) === 'done') emit('done');
}

const fieldClass = 'rounded-lg bg-background px-3 py-1.5 tabular-nums text-primary-text accent-accent';
</script>

<template>
    <BottomSheet
        :open="open"
        :title="editing ? 'Edit steps' : 'Log steps'"
        :heights="['half', 'full']"
        :save-disabled="input === null || busy"
        :cancel-disabled="busy"
        :dismissible="!busy"
        :error="error"
        @cancel="emit('cancel')"
        @save="save"
    >
        <form class="flex flex-col gap-4 p-4" @submit.prevent="save">
            <label class="flex min-h-tap items-center justify-between gap-3">
                <span class="text-primary-text">Day</span>
                <input v-model="date" name="date" type="date" required :readonly="editing" :disabled="busy" :class="fieldClass" />
            </label>
            <label class="flex min-h-tap items-center justify-between gap-3">
                <span class="text-primary-text">Steps</span>
                <input
                    v-model="steps"
                    name="steps"
                    type="text"
                    inputmode="numeric"
                    autocomplete="off"
                    placeholder="0"
                    :disabled="busy"
                    :class="fieldClass"
                    class="w-32 text-right"
                />
            </label>
        </form>
    </BottomSheet>
</template>
