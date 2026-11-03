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
/** The weigh-in this opening edits, kept while the sheet slides away after its row is gone. */
const editing = ref<Row | null>(null);
const weight = ref('');
const waist = ref('');
/** The date and time field's value, "2026-10-10T08:35", read in `offset`. */
const when = ref('');
/**
 * The UTC offset the time is read in. The server has no time zone per user and words its
 * labels in the offset it sends (ReadingTime), so a weigh-in or a missed day keeps the
 * server's, and the field shows the time its row does. A new one for now takes the device's.
 */
const offset = ref('+00:00');

const { send, busy, error } = useWrite((options) => props.reload(options));

const two = (n: number) => String(n).padStart(2, '0');

/** The device's time now, as the field takes it, and the device's offset. */
function deviceNow(): { field: string; offset: string } {
    const date = now();
    const minutes = -date.getTimezoneOffset();
    const sign = minutes < 0 ? '-' : '+';
    return {
        field: `${date.getFullYear()}-${two(date.getMonth() + 1)}-${two(date.getDate())}T${two(date.getHours())}:${two(date.getMinutes())}`,
        offset: `${sign}${two(Math.floor(Math.abs(minutes) / 60))}:${two(Math.abs(minutes) % 60)}`,
    };
}

/** An ISO time the server sent, split into the field's value and its offset. */
function split(iso: string): { field: string; offset: string } {
    const zone = /(Z|[+-]\d{2}:\d{2})$/.exec(iso)?.[1] ?? 'Z';
    return { field: iso.slice(0, 16), offset: zone === 'Z' ? '+00:00' : zone };
}

// Each opening starts from the weigh-in, or blank with a new id (CAP-019).
function reset(): void {
    editing.value = props.row;
    id.value = props.row?.id ?? ulid();
    weight.value = props.row?.weight_field ?? '';
    waist.value = props.row?.waist_field ?? '';
    const start = props.row?.weighed_at ?? props.at;
    const picked = start ? split(start) : deviceNow();
    when.value = picked.field;
    offset.value = picked.offset;
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
    if (kg === null || (waist.value.trim() !== '' && cm === null) || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(when.value)) return null;

    return {
        id: id.value,
        weighed_at: `${when.value}:00${offset.value}`,
        weight: { value: kg, unit: props.units.weight },
        waist: cm === null ? null : { value: cm, unit: props.units.waist },
    };
});

async function finish(outcome: Promise<string | null>): Promise<void> {
    if ((await outcome) === 'done') emit('done');
}

function save(): void {
    if (!input.value) return;
    void finish(send(editing.value ? 'weigh_in_update' : 'weigh_in_log', { ...input.value }, 'The weigh-in could not be saved. Try again.'));
}

function remove(): void {
    if (editing.value) void finish(send('weigh_in_delete', { id: editing.value.id }, 'The weigh-in could not be deleted. Try again.'));
}

const fieldClass = 'min-h-tap w-full rounded-xl bg-background px-3.5 text-primary-text placeholder:text-secondary-text';
</script>

<template>
    <BottomSheet
        :open="open"
        :title="editing ? 'Edit weigh-in' : 'Log weigh-in'"
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
                v-if="editing"
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
