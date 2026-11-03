<script setup lang="ts">
import { ref } from 'vue';
import type { WorkoutShowOutput } from '@/contracts/workout_show';
import BottomSheet from '@/design/BottomSheet.vue';
import { toneClasses } from '@/design/tones';
import LookBackDetail from './LookBackDetail.vue';

type Session = WorkoutShowOutput['look_back'][number];

// The earlier sessions of the mesocycle (WO-008); a tap shows one in place, read-only. The
// script holds which one is open (VIEW-002). Ported from rp-clone's LookBackSheet.
// `session` is a /dev/screens fixture for the one opened; the sheet reads it otherwise.
const props = defineProps<{ payload?: WorkoutShowOutput; session?: WorkoutShowOutput }>();

const emit = defineEmits<{ close: [] }>();

const open = ref<Session | null>(null);

function back(): void {
    if (open.value) open.value = null;
    else emit('close');
}
</script>

<template>
    <BottomSheet :open="true" :title="open?.title ?? 'Look back'" :cancel-title="open ? 'Back' : 'Done'" :save-title="null" :heights="['half', 'full']" @cancel="back">
        <LookBackDetail v-if="open && payload" :key="`${open.week}/${open.day}`" :mesocycle-id="payload.mesocycle_id" :week="open.week" :day="open.day" :payload="session" />
        <div v-else class="flex flex-col p-4">
            <p v-if="!payload?.look_back.length" class="py-2 text-sm text-secondary-text" data-test="none">No earlier sessions in this mesocycle yet.</p>
            <button
                v-for="entry in payload?.look_back"
                :key="`${entry.week}/${entry.day}`"
                type="button"
                class="flex min-h-tap w-full items-center justify-between gap-2 border-t border-secondary-text/25 py-2 text-left first:border-t-0"
                data-test="session"
                @click="open = entry"
            >
                <span class="text-primary-text">{{ entry.title }}</span>
                <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold" :class="toneClasses[entry.status.tone]" data-test="status">{{ entry.status.text }}</span>
            </button>
        </div>
    </BottomSheet>
</template>
