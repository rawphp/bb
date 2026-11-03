<script setup lang="ts">
import { computed, ref } from 'vue';
import { ulid } from '@/app/ulid';
import type { WorkoutSetAddInput } from '@/contracts/workout_set_add';
import type { WorkoutSetDeleteInput } from '@/contracts/workout_set_delete';
import type { WorkoutSetSkipInput } from '@/contracts/workout_set_skip';
import type { WorkoutSetTypeSetInput } from '@/contracts/workout_set_type_set';
import type { WorkoutShowOutput } from '@/contracts/workout_show';
import BottomSheet from '@/design/BottomSheet.vue';
import ChoiceList from '@/design/ChoiceList.vue';
import type { WorkoutWrite } from '../workoutWrite';

// The set menu (WO-020, WO-021): Add set below, Skip set, Delete set and the set's type.
// Done saves a changed type; the action rows send at once and close. The server sends the
// set types. The script holds the type picked (VIEW-002). Ported from rp-clone's SetMenuSheet.
const props = defineProps<{ payload?: WorkoutShowOutput; setId: string }>();

const emit = defineEmits<{ send: [write: WorkoutWrite]; close: [] }>();

const located = computed(() => {
    for (const exercise of props.payload?.exercises ?? []) {
        const set = exercise.sets.find((found) => found.id === props.setId);
        if (set) return { exercise, set };
    }
    return null;
});
const what = computed(() => (located.value ? `${located.value.exercise.name} set ${located.value.set.number}` : ''));
const picked = ref<string | null>(located.value?.set.set_type ?? null);
const changed = computed(() => picked.value !== null && picked.value !== located.value?.set.set_type);
const choices = computed(() => (props.payload?.set_type_choices ?? []).map((choice) => ({ id: choice.value, title: choice.label, detail: choice.note ?? undefined })));

function send(write: WorkoutWrite): void {
    emit('send', write);
    emit('close');
}

function add(): void {
    if (!located.value) return;
    const { exercise, set } = located.value;
    const id = ulid();
    const input: WorkoutSetAddInput = { set_id: id, workout_exercise_id: exercise.id, after_set_id: set.id, set_type: set.set_type };
    send({ name: 'workout_set_add', input: { ...input }, label: `Added a set below ${what.value}`, creates: [id] });
}

function skip(): void {
    if (!located.value) return;
    const input: WorkoutSetSkipInput = { set_id: located.value.set.id, is_skipped: !located.value.set.is_skipped };
    send({ name: 'workout_set_skip', input: { ...input }, label: `${input.is_skipped ? 'Skipped' : 'Unskipped'} ${what.value}` });
}

function remove(): void {
    if (!located.value) return;
    const input: WorkoutSetDeleteInput = { set_id: located.value.set.id };
    send({
        name: 'workout_set_delete',
        input: { ...input },
        label: `Deleted ${what.value}`,
        confirm: { title: `Delete set ${located.value.set.number}?`, message: 'Whatever was logged on it goes with it. It cannot be undone.', action: 'Delete set' },
    });
}

function save(): void {
    const choice = props.payload?.set_type_choices.find((found) => found.value === picked.value);
    if (!located.value || !choice || !changed.value) return;
    const input: WorkoutSetTypeSetInput = { set_id: located.value.set.id, set_type: choice.value as WorkoutSetTypeSetInput['set_type'] };
    send({ name: 'workout_set_type_set', input: { ...input }, label: `Made ${what.value} ${choice.label}` });
}

const row = 'flex min-h-tap w-full items-center gap-3 border-t border-secondary-text/25 py-2 text-left first:border-t-0';
</script>

<template>
    <BottomSheet
        :open="true"
        :title="located ? `Set ${located.set.number} · ${located.exercise.name}` : 'Set'"
        save-title="Done"
        :save-disabled="!changed"
        :heights="['half', 'full']"
        @cancel="emit('close')"
        @save="save"
    >
        <div v-if="located" class="flex flex-col p-4">
            <button type="button" :class="[row, 'text-primary-text']" data-test="action" data-action="add" @click="add">Add set below</button>
            <button type="button" :class="[row, 'text-primary-text']" data-test="action" data-action="skip" @click="skip"> {{ located.set.is_skipped ? 'Unskip set' : 'Skip set' }} </button>
            <button type="button" :class="[row, 'text-tone-warning']" data-test="action" data-action="delete" @click="remove">Delete set</button>
            <h3 class="pb-2 pt-4 text-sm font-semibold text-secondary-text">Set type</h3>
            <ChoiceList v-model="picked" label="Set type" :choices="choices" />
        </div>
    </BottomSheet>
</template>
