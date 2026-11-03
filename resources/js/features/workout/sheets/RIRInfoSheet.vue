<script setup lang="ts">
import type { WorkoutShowOutput } from '@/contracts/workout_show';
import BottomSheet from '@/design/BottomSheet.vue';

// What this week's target and RIR mean (WO-007), from a set's info icon. Ported from
// rp-clone's RIRInfoSheet.
defineProps<{ payload?: WorkoutShowOutput }>();

const emit = defineEmits<{ close: [] }>();
</script>

<template>
    <BottomSheet :open="true" title="Reps and RIR" cancel-title="Done" :save-title="null" :heights="['half', 'full']" @cancel="emit('close')">
        <div v-if="payload" class="flex flex-col gap-3 p-4 text-primary-text">
            <h3 class="text-lg font-semibold">{{ payload.rir_info.title }}</h3>
            <p>{{ payload.rir_info.body }}</p>
            <p class="text-sm text-secondary-text">RIR is reps in reserve: how many more reps you could have done.</p>
            <RouterLink v-if="payload.rir_info.article" :to="`/learn/${payload.rir_info.article}`" class="inline-flex min-h-tap items-center self-start text-accent">Read more</RouterLink>
        </div>
    </BottomSheet>
</template>
