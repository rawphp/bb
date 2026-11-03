<script setup lang="ts">
import type { WorkoutShowOutput } from '@/contracts/workout_show';
import BottomSheet from '@/design/BottomSheet.vue';

// What the workout screen does not say itself (WO-023, WO-024, WO-030, WO-052): resting by
// feel, warm-ups, dumbbells, the deload, and training while sick, alongside a sport and long
// sessions. The server sends the sections. Ported from rp-clone's WorkoutHelpSheet.
defineProps<{ payload?: WorkoutShowOutput }>();

const emit = defineEmits<{ close: [] }>();
</script>

<template>
    <BottomSheet :open="true" title="Workout help" cancel-title="Done" :save-title="null" @cancel="emit('close')">
        <div class="flex flex-col gap-5 p-4">
            <section v-for="section in payload?.help" :key="section.title" class="flex flex-col gap-1" data-test="section">
                <h3 class="text-lg font-semibold text-primary-text">{{ section.title }}</h3>
                <p class="text-primary-text">{{ section.body }}</p>
                <RouterLink v-if="section.article" :to="`/learn/${section.article}`" class="inline-flex min-h-tap items-center self-start text-accent">Read more</RouterLink>
            </section>
        </div>
    </BottomSheet>
</template>
