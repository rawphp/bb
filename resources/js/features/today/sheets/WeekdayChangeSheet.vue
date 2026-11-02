<script setup lang="ts">
import BottomSheet from '@/design/BottomSheet.vue';
import ChoiceList, { type Choice } from '@/design/ChoiceList.vue';
import type { FewerDays } from './useWeekdayChange';

// SCH-039: what a change to fewer training weekdays does to the running mesocycle, asked
// when the server answers that it must change shape. A tap answers at once; Cancel saves
// nothing. Shown by Edit schedule and Settings through useWeekdayChange. Ported from
// rp-clone's WeekdayChangeSheet.
withDefaults(defineProps<{ open?: boolean; busy?: boolean; error?: string | null }>(), { open: true, busy: false, error: null });

const emit = defineEmits<{ choose: [fewerDays: FewerDays]; cancel: [] }>();

const choices: Choice<FewerDays>[] = [
    { id: 'restructure', title: 'Restructure from next week', detail: 'The Meso Builder lays out your muscles over your new training days.' },
    { id: 'extend', title: 'Keep all sessions', detail: 'Each week finishes fewer of your sessions, in order, so your mesocycle runs longer.' },
];
</script>

<template>
    <BottomSheet
        :open="open"
        title="Training days changed"
        :save-title="null"
        :cancel-disabled="busy"
        :dismissible="!busy"
        :heights="['half', 'full']"
        @cancel="emit('cancel')"
        @save="() => undefined"
    >
        <div class="flex flex-col gap-4 p-4">
            <p class="text-sm text-secondary-text">Your new training days start next plan week. What should your mesocycle do?</p>
            <ChoiceList label="What your mesocycle does" :choices="choices" :model-value="null" :disabled="busy" :error="error" @update:model-value="(id) => emit('choose', id)" />
        </div>
    </BottomSheet>
</template>
