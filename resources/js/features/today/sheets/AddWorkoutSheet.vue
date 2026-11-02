<script setup lang="ts">
import { computed, ref } from 'vue';
import { ulid } from '@/app/ulid';
import type { OtherActivitySaveInput } from '@/contracts/other_activity_save';
import type { WorkoutMoveInput } from '@/contracts/workout_move';
import BottomSheet from '@/design/BottomSheet.vue';
import Stepper from '@/features/onboarding/parts/Stepper.vue';
import FormSection from './FormSection.vue';
import SegmentedPicker from './SegmentedPicker.vue';
import SheetBody from './SheetBody.vue';
import TimeField from './TimeField.vue';
import { useDaySheet, type DaySheetProps } from './useDaySheet';

type Intensity = OtherActivitySaveInput['intensity'];

// SCH-032: the schedule menu's Add workout. Either the next session of the current
// mesocycle, moved to this day, or an Other activity (sport or cardio) logged for
// nutrition only, at the intensity the user picks (NUT-005). A day holds one activity, so
// on a day that has one the sheet edits it. The server says when no session can come here
// (SCH-038) and offers nothing on a past day (NUT-024). Ported from rp-clone's AddWorkoutSheet.
const props = defineProps<DaySheetProps>();

const emit = defineEmits<{ close: [saved: boolean] }>();

const { data, failure, load, send, busy, error, seed } = useDaySheet(props);

const intensities: { value: Intensity; label: string }[] = [
    { value: 'light', label: 'Light' },
    { value: 'moderate', label: 'Moderate' },
    { value: 'hard', label: 'Hard' },
];

const adding = computed(() => data.value?.add_workout);
// A new activity's id, made once, so a retry after no answer is the same create (CAP-019).
const newId = ulid();
const name = ref('');
const start = ref('18:00');
const minutes = ref(45);
const intensity = ref<Intensity>('moderate');
seed((day) => {
    const activity = day.add_workout?.activity;
    if (!activity) return;
    name.value = activity.name;
    start.value = activity.start;
    minutes.value = activity.minutes;
    intensity.value = activity.intensity;
});

const canSave = computed(() => !!adding.value && name.value.trim() !== '' && start.value !== '' && !busy.value);

async function moveSession(): Promise<void> {
    const session = adding.value?.session;
    if (!data.value || !session) return;
    const input = { mesocycle_id: session.mesocycle_id, week: session.week, day: session.day, date: data.value.date } satisfies WorkoutMoveInput;
    if ((await send('workout_move', input, 'The session could not be moved here.')) === 'done') emit('close', true);
}

async function save(): Promise<void> {
    if (!data.value || !adding.value || !canSave.value) return;
    const input = {
        id: adding.value.activity?.id ?? newId,
        date: data.value.date,
        name: name.value.trim(),
        start: start.value,
        minutes: minutes.value,
        intensity: intensity.value,
    } satisfies OtherActivitySaveInput;
    if ((await send('other_activity_save', input, 'The activity could not be saved.')) === 'done') emit('close', true);
}
</script>

<template>
    <BottomSheet
        :open="true"
        :title="adding?.activity ? 'Edit activity' : 'Add workout'"
        :save-disabled="!canSave"
        :cancel-disabled="busy"
        :dismissible="!busy"
        :error="error"
        :pending="pending"
        @cancel="emit('close', false)"
        @save="save"
    >
        <SheetBody :loaded="!!data" :failure="failure" what="This day" @retry="load">
            <template v-if="data && adding">
                <FormSection header="Mesocycle session" :footer="adding.session ? `Moves this session to ${data.title}.` : undefined">
                    <button
                        v-if="adding.session"
                        type="button"
                        data-test="session"
                        :disabled="busy"
                        class="flex min-h-tap w-full items-center gap-3 py-2 text-left text-accent disabled:opacity-50"
                        @click="moveSession"
                    >
                        <svg aria-hidden="true" viewBox="0 0 24 24" class="size-6 shrink-0" fill="none" stroke="currentColor" stroke-width="1.75">
                            <path d="M3 9v6M6 7v10M18 7v10M21 9v6M6 12h12" stroke-linecap="round" />
                        </svg>
                        {{ adding.session.title }}
                    </button>
                    <div v-else class="flex min-h-tap flex-col justify-center gap-0.5 py-2 text-secondary-text">
                        <span>Next planned session</span>
                        <span v-if="adding.session_message" class="text-sm">{{ adding.session_message }}</span>
                    </div>
                </FormSection>
                <FormSection header="Other activity" footer="Logged for nutrition only. Two activities in one day go in as one, like &quot;Run + football&quot;.">
                    <input
                        v-model="name"
                        type="text"
                        aria-label="Name"
                        placeholder="Name"
                        :disabled="busy"
                        class="min-h-tap w-full bg-transparent py-2 text-primary-text placeholder:text-secondary-text/70 focus:outline-none"
                    />
                    <TimeField v-model="start" label="Starts" :disabled="busy" />
                    <Stepper v-model="minutes" label="Length" :text="`Length: ${minutes} min`" :min="5" :max="Math.max(300, minutes)" :step="5" :disabled="busy" />
                    <div class="py-2">
                        <SegmentedPicker v-model="intensity" label="Intensity" :options="intensities" :disabled="busy" />
                    </div>
                </FormSection>
            </template>
            <p v-else-if="data" class="px-4 text-secondary-text">A workout can't be added to a past day.</p>
        </SheetBody>
    </BottomSheet>
</template>
