<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { KEYPAD } from '@/app/keypad';
import { pendingInput } from '@/app/outbox';
import { ulid } from '@/app/ulid';
import type { WorkoutSetAddInput } from '@/contracts/workout_set_add';
import type { WorkoutSetDeleteInput } from '@/contracts/workout_set_delete';
import type { WorkoutSetLogInput } from '@/contracts/workout_set_log';
import type { WorkoutSetSkipInput } from '@/contracts/workout_set_skip';
import type { WorkoutSetTypeSetInput } from '@/contracts/workout_set_type_set';
import type { WorkoutShowOutput } from '@/contracts/workout_show';
import ActionMenu, { type ActionMenuItem } from '@/design/ActionMenu.vue';
import HelpButton from '@/design/HelpButton.vue';
import PendingMark from '@/design/PendingMark.vue';
import type { WorkoutWrite } from './workoutWrite';

type Exercise = WorkoutShowOutput['exercises'][number];
type WorkoutSet = Exercise['sets'][number];
type SetType = WorkoutSetTypeSetInput['set_type'];

// One set (WO-003): its menu, number and type badge, the weight and reps fields, the RIR
// info icon and the Log box. Weight and reps use the number pads (UI-020) and are sent when
// a field is left or Log is tapped. The server fills both from the target (WO-004); a typed
// weight looks its reps up in the set's rep table (WO-005, CAP-016), and a weight not in it
// shows the server's "just log reps" text. A set with a write waiting shows what the user
// typed, with a pending mark (OFF-009). The script holds the text in the fields and whether
// the reps were typed by hand (VIEW-002). Ported from rp-clone's WorkoutSetRow.
const props = withDefaults(
    defineProps<{
        set: WorkoutSet;
        exercise: Exercise;
        unit: WorkoutShowOutput['weight_unit'];
        editable: boolean;
        rirInfo: WorkoutShowOutput['rir_info'];
        setTypeChoices: WorkoutShowOutput['set_type_choices'];
        /** WO-006: the first set's typed weight, shown on a later set that has none of its own. */
        carriedWeight?: string | null;
    }>(),
    { carriedWeight: null },
);

const emit = defineEmits<{ send: [write: WorkoutWrite]; 'weight-typed': [text: string] }>();

// OFF-009: the newest waiting set_log for this set, as the user typed it.
const own = computed(() => pendingInput<WorkoutSetLogInput>('workout_set_log', (input) => input.set_id === props.set.id));

/** What the server (or the waiting write) holds for the fields, as text. */
function savedWeight(): string {
    if (own.value) return own.value.weight ? String(own.value.weight.value) : '';
    if (props.set.weight) return String(props.set.weight.value);
    return props.carriedWeight ?? '';
}

function savedReps(): string {
    const reps = own.value ? own.value.reps : props.set.reps;
    return reps === null || reps === undefined ? '' : String(reps);
}

const weightText = ref(savedWeight());
const repsText = ref(savedReps());
const focused = ref<'weight' | 'reps' | null>(null);
// Reps the user typed stay as typed when the weight changes; otherwise the table's show.
const repsTyped = ref(false);
// What a left field last sent, so a second change event before the outbox shows it sends nothing more.
let lastSent: string | null = null;

watch([() => props.set, own, () => props.carriedWeight], () => {
    if (focused.value !== 'weight') weightText.value = savedWeight();
    if (focused.value !== 'reps') repsText.value = savedReps();
    if (focused.value === null) repsTyped.value = false;
    lastSent = null;
});

const logged = computed(() => own.value?.is_logged ?? props.set.is_logged);
const locked = computed(() => !props.editable || props.set.is_skipped);

/** The field's text as a number, a comma taken as the decimal point; NaN when it is not one. */
function numberIn(text: string): number {
    const trimmed = text.trim().replace(',', '.');
    return trimmed === '' ? Number.NaN : Number(trimmed);
}

// CAP-016: the typed weight looked up in the server's table.
const tableRow = computed(() => {
    const weight = numberIn(weightText.value);
    return props.set.rep_table.find((row) => row.weight.value === weight);
});
const offTable = computed(() => props.set.rep_table.length > 0 && weightText.value.trim() !== '' && tableRow.value === undefined);
const repsPlaceholder = computed(() => (offTable.value ? (props.set.off_table_text ?? props.set.reps_placeholder) : props.set.reps_placeholder));
// WO-004: the planned weight is in use.
const showsTarget = computed(() => props.set.target_weight !== null && props.set.target_weight !== undefined && numberIn(weightText.value) === props.set.target_weight.value);

function weightInput(): void {
    if (props.set.number === 1 && props.exercise.carries_first_weight) emit('weight-typed', weightText.value);
    if (repsTyped.value || props.set.rep_table.length === 0) return;
    // WO-005: a weight moved within the table shows its reps; off it, only the RIR text.
    repsText.value = tableRow.value ? String(tableRow.value.reps) : '';
}

function repsInput(): void {
    repsTyped.value = true;
}

/** The set_log input for what the fields hold, or null when a field is not a number. */
function logInput(isLogged: boolean): WorkoutSetLogInput | null {
    const weight = numberIn(weightText.value);
    const reps = numberIn(repsText.value);
    const weightEmpty = weightText.value.trim() === '';
    const repsEmpty = repsText.value.trim() === '';
    if ((!weightEmpty && !(weight >= 0)) || (!repsEmpty && !(Number.isInteger(reps) && reps >= 0))) return null;
    return {
        set_id: props.set.id,
        weight: weightEmpty ? null : { value: weight, unit: props.unit },
        reps: repsEmpty ? null : reps,
        is_logged: isLogged,
    };
}

function label(input: WorkoutSetLogInput, verb: string): string {
    const parts = [input.weight ? `${input.weight.value} ${input.weight.unit}` : null, input.reps === null ? null : `${input.reps} reps`].filter(Boolean);
    return `${verb} ${props.exercise.name} set ${props.set.number}${parts.length ? `: ${parts.join(' × ')}` : ''}`;
}

/** A field was left: send what changed, keeping Log as it is. */
function commit(): void {
    focused.value = null;
    const input = logInput(logged.value);
    if (input === null) {
        weightText.value = savedWeight();
        repsText.value = savedReps();
        return;
    }
    const typed = `${weightText.value.trim()}|${repsText.value.trim()}`;
    if (typed === `${savedWeight()}|${savedReps()}` || typed === lastSent) return;
    lastSent = typed;
    emit('send', { name: 'workout_set_log', input: { ...input }, label: label(input, 'Changed') });
}

function toggleLog(): void {
    const input = logInput(!logged.value);
    if (input === null) return;
    emit('send', { name: 'workout_set_log', input: { ...input }, label: label(input, input.is_logged ? 'Logged' : 'Unlogged'), haptic: input.is_logged });
}

// WO-020, WO-021: the set menu.
const menuItems = computed<ActionMenuItem[]>(() => [
    { key: 'add', title: 'Add set below' },
    { key: 'skip', title: props.set.is_skipped ? 'Unskip set' : 'Skip set' },
    { key: 'delete', title: 'Delete set', destructive: true },
    ...props.setTypeChoices.map((choice) => ({
        key: `type:${choice.value}`,
        title: `Set type: ${choice.label}`,
        detail: choice.value === props.set.set_type ? 'Current' : choice.note,
        disabled: choice.value === props.set.set_type,
    })),
]);

const what = computed(() => `${props.exercise.name} set ${props.set.number}`);

function choose(key: string): void {
    if (key === 'add') {
        const id = ulid();
        const input: WorkoutSetAddInput = { set_id: id, workout_exercise_id: props.exercise.id, after_set_id: props.set.id, set_type: props.set.set_type };
        emit('send', { name: 'workout_set_add', input: { ...input }, label: `Added a set below ${what.value}`, creates: [id] });
    } else if (key === 'skip') {
        const input: WorkoutSetSkipInput = { set_id: props.set.id, is_skipped: !props.set.is_skipped };
        emit('send', { name: 'workout_set_skip', input: { ...input }, label: `${input.is_skipped ? 'Skipped' : 'Unskipped'} ${what.value}` });
    } else if (key === 'delete') {
        const input: WorkoutSetDeleteInput = { set_id: props.set.id };
        emit('send', {
            name: 'workout_set_delete',
            input: { ...input },
            label: `Deleted ${what.value}`,
            confirm: { title: `Delete set ${props.set.number}?`, message: 'Whatever was logged on it goes with it. It cannot be undone.', action: 'Delete set' },
        });
    } else if (key.startsWith('type:')) {
        const choice = props.setTypeChoices.find((found) => `type:${found.value}` === key);
        if (!choice) return;
        const input: WorkoutSetTypeSetInput = { set_id: props.set.id, set_type: choice.value as SetType };
        emit('send', { name: 'workout_set_type_set', input: { ...input }, label: `Made ${what.value} ${choice.label}` });
    }
}

const field =
    'h-tap w-full min-w-0 rounded-[10px] border bg-background px-1.5 text-center text-xl font-medium tabular-nums text-primary-text outline-none focus:border-2 focus:border-accent disabled:opacity-100';
</script>

<template>
    <div :data-set="set.id" :class="set.is_skipped ? 'opacity-50' : ''">
        <div class="flex items-center gap-1 rounded-xl py-0.5" :class="logged ? 'bg-accent/10' : ''">
            <ActionMenu :label="`Set ${set.number} menu`" :items="menuItems" align="start" :disabled="!editable" data-test="set-menu" @select="choose">
                <svg viewBox="0 0 24 24" class="size-6 text-accent" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <circle cx="12" cy="12" r="9.25" />
                    <path d="M8 12h.01M12 12h.01M16 12h.01" stroke-linecap="round" stroke-width="2.5" />
                </svg>
            </ActionMenu>
            <div class="flex w-[34px] shrink-0 flex-col items-center">
                <span class="font-semibold tabular-nums text-primary-text">{{ set.number }}</span>
                <span
                    v-if="set.badge"
                    class="rounded-full bg-accent px-1.5 text-[11px] font-bold text-on-accent"
                    :aria-label="set.set_type === 'myorep' ? 'Myorep' : 'Myorep match'"
                    data-test="badge"
                >
                    {{ set.badge }}
                </span>
            </div>
            <div class="relative min-w-0 flex-1">
                <input
                    v-model="weightText"
                    type="text"
                    v-bind="KEYPAD.weight"
                    placeholder="0"
                    :class="[field, focused === 'weight' ? 'border-accent' : 'border-secondary-text/25']"
                    :disabled="locked"
                    :aria-label="`Set ${set.number} ${exercise.weight_label}`"
                    data-test="weight"
                    @focus="focused = 'weight'"
                    @input="weightInput"
                    @change="commit"
                    @blur="focused === 'weight' && commit()"
                />
                <svg
                    v-if="showsTarget"
                    viewBox="0 0 24 24"
                    class="pointer-events-none absolute left-1 top-1 size-3 text-accent"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2.25"
                    role="img"
                    aria-label="Planned target"
                    data-test="target"
                >
                    <circle cx="12" cy="12" r="8" />
                    <circle cx="12" cy="12" r="3" />
                    <path d="M12 1v4M12 19v4M1 12h4M19 12h4" />
                </svg>
            </div>
            <input
                v-model="repsText"
                type="text"
                v-bind="KEYPAD.reps"
                :placeholder="repsPlaceholder"
                :class="[field, 'flex-1 placeholder:text-base placeholder:font-normal', focused === 'reps' ? 'border-accent' : 'border-secondary-text/25']"
                :disabled="locked"
                :aria-label="`Set ${set.number} reps`"
                :aria-description="repsPlaceholder"
                data-test="reps"
                @focus="focused = 'reps'"
                @input="repsInput"
                @change="commit"
                @blur="focused === 'reps' && commit()"
            />
            <!-- WO-007: what the target and this week's RIR mean. -->
            <HelpButton :title="rirInfo.title" :message="rirInfo.body" :to="rirInfo.article ? `/learn/${rirInfo.article}` : undefined" data-test="rir-info" />
            <button
                type="button"
                role="checkbox"
                class="flex min-h-tap min-w-tap shrink-0 items-center justify-center disabled:opacity-100"
                :class="logged ? 'text-accent' : 'text-secondary-text'"
                :aria-checked="logged"
                :aria-label="`Log set ${set.number}`"
                :aria-description="set.is_skipped ? 'Skipped' : undefined"
                :disabled="locked"
                data-test="log"
                @pointerdown.prevent
                @click="toggleLog"
            >
                <svg viewBox="0 0 24 24" class="size-8" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <rect v-if="!logged" x="3.5" y="3.5" width="17" height="17" rx="3.5" />
                    <template v-if="set.is_skipped">
                        <path d="M8 12h8" stroke-linecap="round" stroke-width="2" />
                    </template>
                    <template v-else-if="logged">
                        <rect x="3.5" y="3.5" width="17" height="17" rx="3.5" fill="currentColor" />
                        <path d="m7.5 12 3 3 6-6" stroke="var(--color-on-accent)" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" />
                    </template>
                </svg>
            </button>
        </div>
        <p v-if="own" class="pl-[86px]" data-test="pending"><PendingMark /></p>
    </div>
</template>
