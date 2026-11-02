<script setup lang="ts">
import { ulid } from '@/app/ulid';
import ActionMenu, { type ActionMenuItem } from '@/design/ActionMenu.vue';
import { computed } from 'vue';
import SlotRowView from './SlotRowView.vue';
import type { Build, BuildDaysInput, BuildMuscle, BuildSlot } from './types';

type SlotInput = BuildDaysInput[number]['slots'][number];

// The exercise for every slot (MESO-013, MESO-014, MESO-016), shared by all four routes.
// Ported from rp-clone's SlotsStepView. Every edit is the whole of the days, as the server
// sent them, with the one change; the server decides what that makes of the draft.
const props = defineProps<{ build: Build; error?: string | null; disabled?: boolean; pending?: boolean }>();

const emit = defineEmits<{ save: [days: BuildDaysInput]; pick: [] }>();

// A new slot's sets: rp-clone's MesocycleBuildModel.defaultSets.
const DEFAULT_SETS = 2;

const addItems = computed<ActionMenuItem[]>(() => props.build.exercise_options.map((m) => ({ key: m.muscle, title: m.muscle_title })));

const toInput = (slot: BuildSlot): SlotInput => ({ slot_id: slot.id, muscle: slot.muscle, exercise_id: slot.exercise_id ?? null, sets: slot.sets });

/** Every day as it is, with `edit` applied to each day's slots. */
function save(edit: (slots: SlotInput[], dayId: string) => SlotInput[]): void {
    emit('save', props.build.days.map((day) => ({ day_id: day.id, slots: edit(day.slots.map(toInput), day.id) })));
}

function change(slotId: string, changes: Partial<SlotInput>): void {
    save((slots) => slots.map((slot) => (slot.slot_id === slotId ? { ...slot, ...changes } : slot)));
}

function add(dayId: string, muscle: BuildMuscle['muscle']): void {
    save((slots, id) => (id === dayId ? [...slots, { slot_id: ulid(), muscle, exercise_id: null, sets: DEFAULT_SETS }] : slots));
}

function remove(slotId: string): void {
    save((slots) => slots.filter((slot) => slot.slot_id !== slotId));
}

function exercisesFor(slot: BuildSlot): BuildMuscle['exercises'] {
    return props.build.exercise_options.find((m) => m.muscle === slot.muscle)?.exercises ?? [];
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between gap-2">
            <p v-if="error" role="alert" class="text-sm text-tone-warning">Your draft could not be saved: {{ error }}</p>
            <span v-else />
            <button
                type="button"
                class="flex min-h-tap min-w-tap items-center justify-center rounded-full text-accent disabled:opacity-50"
                aria-label="Pick for me"
                title="Fills every empty exercise"
                :disabled="disabled || !build.can_pick_for_me"
                @click="emit('pick')"
            >
                <svg viewBox="0 0 24 24" class="size-5" aria-hidden="true" fill="currentColor"><path d="M13 2L4 14h6l-1 8 9-12h-6z" /></svg>
            </button>
        </div>

        <section v-for="day in build.days" :key="day.id" data-test="day" :aria-label="day.label">
            <h3 class="pb-1.5 text-sm text-secondary-text">{{ day.label }}</h3>
            <div class="divide-y divide-secondary-text/15">
                <SlotRowView
                    v-for="slot in day.slots"
                    :key="slot.id"
                    :entry="slot"
                    :sets-options="build.sets_options"
                    :exercises="exercisesFor(slot)"
                    :disabled="disabled"
                    :pending="pending"
                    @sets="(sets) => change(slot.id, { sets })"
                    @exercise="(exerciseId) => change(slot.id, { exercise_id: exerciseId })"
                    @delete="remove(slot.id)"
                />
            </div>
            <ActionMenu label="Add exercise" :items="addItems" :disabled="disabled" @select="(muscle) => add(day.id, muscle as BuildMuscle['muscle'])">
                <span class="flex items-center gap-1.5 px-2 text-accent">
                    <svg viewBox="0 0 24 24" class="size-4" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
                    Add exercise
                </span>
            </ActionMenu>
        </section>
    </div>
</template>
