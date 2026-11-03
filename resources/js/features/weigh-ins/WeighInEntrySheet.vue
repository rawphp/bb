<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { now } from '@/app/clock';
import { ulid } from '@/app/ulid';
import { useWrite } from '@/app/useWrite';
import type { WeighInLogInput } from '@/contracts/weigh_in_log';
import type { WeighInsShowOutput } from '@/contracts/weigh_ins_show';
import BottomSheet from '@/design/BottomSheet.vue';

// Log a weigh-in, or edit and delete one (WT-002, WT-004, WT-006), with an optional waist
// (WT-021). Ported from rp-clone's WeighInEntrySheet.swift. The server checks the values
// and works out the trend; this form only reads what was typed as numbers (a comma counts
// as a decimal point) in the units the server sent. `reload` is the screen's read, which
// useWrite runs after each write.
type Row = WeighInsShowOutput['rows'][number];

const props = withDefaults(
    defineProps<{
        open: boolean;
        units: WeighInsShowOutput['units'];
        reload: (options?: { fresh?: boolean }) => Promise<void>;
        /** The weigh-in to edit; null logs a new one. */
        row?: Row | null;
        /** When a new weigh-in starts, as an ISO time; now when null. */
        at?: string | null;
    }>(),
    { row: null, at: null },
);

const emit = defineEmits<{ cancel: []; done: [] }>();

const id = ref('');
const weight = ref('');
const waist = ref('');
/** The date and time field's value, in the device's time zone: "2026-10-10T08:35". */
const when = ref('');

const { send, busy, error } = useWrite((options) => props.reload(options));

const two = (n: number) => String(n).padStart(2, '0');

function local(date: Date): string {
    return `${date.getFullYear()}-${two(date.getMonth() + 1)}-${two(date.getDate())}T${two(date.getHours())}:${two(date.getMinutes())}`;
}

// Each opening starts from the weigh-in, or blank with a new id (CAP-019).
function reset(): void {
    id.value = props.row?.id ?? ulid();
    weight.value = props.row?.weight_field ?? '';
    waist.value = props.row?.waist_field ?? '';
    const start = props.row?.weighed_at ?? props.at;
    when.value = local(start ? new Date(start) : now());
    error.value = null;
}

watch(
    () => props.open,
    (open) => open && reset(),
    { immediate: true },
);

/** What was typed as a number, or null when it is not one. */
function typed(text: string): number | null {
    const cleaned = text.trim().replace(',', '.');
    return /^(\d+\.?\d*|\.\d+)$/.test(cleaned) ? Number(cleaned) : null;
}

const input = computed<WeighInLogInput | null>(() => {
    const kg = typed(weight.value);
    const cm = waist.value.trim() === '' ? null : typed(waist.value);
    const at = new Date(when.value);
    if (kg === null || (waist.value.trim() !== '' && cm === null) || Number.isNaN(at.getTime())) return null;

    return {
        id: id.value,
        weighed_at: at.toISOString(),
        weight: { value: kg, unit: props.units.weight },
        waist: cm === null ? null : { value: cm, unit: props.units.waist },
    };
});

async function finish(outcome: Promise<string | null>): Promise<void> {
    if ((await outcome) === 'done') emit('done');
}

function save(): void {
    if (!input.value) return;
    const editing = props.row !== null;
    void finish(send(editing ? 'weigh_in_update' : 'weigh_in_log', { ...input.value }, 'The weigh-in could not be saved. Try again.'));
}

function remove(): void {
    if (props.row) void finish(send('weigh_in_delete', { id: props.row.id }, 'The weigh-in could not be deleted. Try again.'));
}

const fieldClass = 'min-h-tap w-full rounded-xl bg-background px-3.5 text-primary-text placeholder:text-secondary-text';
</script>

<template>
    <BottomSheet
        :open="open"
        :title="row ? 'Edit weigh-in' : 'Log weigh-in'"
        :heights="['half', 'full']"
        :save-disabled="input === null || busy"
        :cancel-disabled="busy"
        :dismissible="!busy"
        :error="error"
        @cancel="emit('cancel')"
        @save="save"
    >
        <form class="flex flex-col gap-4 p-4" @submit.prevent="save">
            <label class="flex items-center gap-2 rounded-xl bg-background pr-3.5">
                <input
                    v-model="weight"
                    name="weight"
                    type="text"
                    inputmode="decimal"
                    autocomplete="off"
                    placeholder="Weight"
                    :aria-label="`Weight in ${units.weight}`"
                    :disabled="busy"
                    :class="fieldClass"
                    class="text-2xl tabular-nums"
                />
                <span class="text-secondary-text" aria-hidden="true">{{ units.weight }}</span>
            </label>
            <label class="flex items-center gap-2 rounded-xl bg-background pr-3.5">
                <input
                    v-model="waist"
                    name="waist"
                    type="text"
                    inputmode="decimal"
                    autocomplete="off"
                    placeholder="Waist (optional)"
                    :aria-label="`Waist in ${units.waist}, optional`"
                    :disabled="busy"
                    :class="fieldClass"
                    class="text-base tabular-nums"
                />
                <span class="text-secondary-text" aria-hidden="true">{{ units.waist }}</span>
            </label>
            <label class="flex min-h-tap items-center justify-between gap-3">
                <span class="text-primary-text">Date and time</span>
                <input
                    v-model="when"
                    name="weighed_at"
                    type="datetime-local"
                    required
                    :disabled="busy"
                    class="rounded-lg bg-background px-3 py-1.5 tabular-nums text-primary-text accent-accent"
                />
            </label>
            <button
                v-if="row"
                type="button"
                data-test="delete-weigh-in"
                :disabled="busy"
                class="flex min-h-tap items-center justify-center gap-2 rounded-xl bg-accent/15 text-accent active:opacity-70 disabled:opacity-50"
                @click="remove"
            >
                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6M14 11v6" />
                </svg>
                Delete weigh-in
            </button>
        </form>
    </BottomSheet>
</template>
