<script setup lang="ts">
import { computed, ref } from 'vue';
import type { ReviewAnswerInput } from '@/contracts/review_answer';
import type { ReviewShowOutput } from '@/contracts/review_show';
import BottomSheet from '@/design/BottomSheet.vue';
import Stepper from '@/features/onboarding/parts/Stepper.vue';
import SegmentedPicker from '@/features/today/sheets/SegmentedPicker.vue';

type Preview = NonNullable<ReviewShowOutput['preview']>;
type Day = Preview['days'][number];
export type ReviewMove = NonNullable<ReviewAnswerInput['moves']>[number];

// REV-004, NUT-021: move calories between the tapped day and another, by the server's
// step within its bounds. The week's total stays the same; the server rebuilds the
// preview from the moves. rp-clone's ReviewMoveSheet.
const props = defineProps<{ day: Day; preview: Preview; busy: boolean; error: string | null }>();
const emit = defineEmits<{ cancel: []; move: [move: ReviewMove] }>();

// The days the calories can go to or come from.
const others = computed(() => props.preview.days.filter((d) => d.index !== props.day.index && d.can_move && !d.is_locked));
const other = ref<number | null>(others.value[0]?.index ?? null);
// rp-clone starts every move at 100 kcal.
const kcal = ref(100);
// Give this day calories instead of taking them.
const direction = ref<'take' | 'give'>('take');
const directions = [
    { value: 'take', label: 'Take from this day' },
    { value: 'give', label: 'Give to this day' },
] as const;

const canMove = computed(() => other.value !== null && !props.day.is_locked && !props.busy);

function save(): void {
    if (other.value === null || !canMove.value) return;
    const gives = direction.value === 'give';
    emit('move', { from_index: gives ? other.value : props.day.index, to_index: gives ? props.day.index : other.value, kcal: kcal.value });
}
</script>

<template>
    <BottomSheet
        :open="true"
        :title="preview.move.title"
        save-title="Move"
        :save-disabled="!canMove"
        :cancel-disabled="busy"
        :dismissible="!busy"
        :heights="['half']"
        :error="error"
        @cancel="emit('cancel')"
        @save="save"
    >
        <div class="flex flex-col gap-4 p-4" data-test="move-sheet">
            <h3 class="font-semibold text-primary-text">{{ day.date_label }}</h3>
            <SegmentedPicker v-model="direction" label="Direction" :options="[...directions]" :disabled="busy" />
            <label class="flex min-h-tap items-center justify-between gap-3">
                <span>{{ direction === 'give' ? 'From' : 'To' }}</span>
                <select v-model.number="other" data-test="other-day" class="min-h-tap rounded-lg bg-background px-2 text-base text-accent" :disabled="busy">
                    <option v-for="d in others" :key="d.index" :value="d.index">{{ d.date_label }} · {{ d.kcal_label }}</option>
                </select>
            </label>
            <Stepper v-model="kcal" label="Calories" :text="`${kcal} kcal`" :min="preview.move.min_kcal" :max="preview.move.max_kcal" :step="preview.move.step_kcal" :disabled="busy" />
        </div>
    </BottomSheet>
</template>
