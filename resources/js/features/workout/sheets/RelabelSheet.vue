<script setup lang="ts">
import { computed, ref } from 'vue';
import type { WorkoutDayRelabelInput } from '@/contracts/workout_day_relabel';
import type { WorkoutShowOutput } from '@/contracts/workout_show';
import BottomSheet from '@/design/BottomSheet.vue';
import type { WorkoutWrite } from '../workoutWrite';

// Rename this day of the mesocycle (WO-031). Ported from rp-clone's RelabelSheet.
const props = defineProps<{ payload?: WorkoutShowOutput }>();

const emit = defineEmits<{ send: [write: WorkoutWrite]; close: [] }>();

const text = ref(props.payload?.day_label ?? '');
const blank = computed(() => text.value.trim() === '');

function save(): void {
    if (!props.payload || blank.value) return;
    const input: WorkoutDayRelabelInput = { workout_id: props.payload.workout_id, label: text.value.trim() };
    emit('send', { name: 'workout_day_relabel', input: { ...input }, label: `Renamed the day to ${input.label}` });
    emit('close');
}
</script>

<template>
    <BottomSheet :open="true" title="Relabel day" :save-disabled="blank" :heights="['half']" @cancel="emit('close')" @save="save">
        <div class="flex flex-col gap-2.5 p-4">
            <input v-model="text" type="text" aria-label="Day name" class="min-h-tap w-full rounded-xl bg-background px-3.5 text-primary-text outline-none focus:ring-2 focus:ring-accent" />
            <p class="text-sm text-secondary-text">Renames this day in the mesocycle.</p>
        </div>
    </BottomSheet>
</template>
