<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { isPending } from '@/app/outbox';
import PendingMark from '@/design/PendingMark.vue';
import { activeWorkout } from '@/features/workout/activeWorkout';

// NAV-004: the bar above the tab bar while a workout is under way ("Upper A · 4 of 6
// exercises"), so the user can leave the workout and come back. A tap opens it again. It
// hides on the workout itself. The words are the server's, from the workout's last read,
// and show as pending while a change to it waits to sync (OFF-008). AppShell finds this file
// and puts it in its slot. Ported from rp-clone's ActiveWorkoutBar.
const route = useRoute();
const router = useRouter();

const bar = computed(() => (activeWorkout.value && route.path !== activeWorkout.value.path ? activeWorkout.value : null));
const pending = computed(() => isPending('workout_show'));

function open(): void {
    if (bar.value) void router.push(bar.value.path);
}
</script>

<template>
    <div v-if="bar" class="bg-background px-3 py-1.5">
        <button
            type="button"
            class="flex min-h-tap w-full items-center gap-2.5 rounded-[14px] bg-accent px-4 text-sm font-semibold text-on-accent active:opacity-80"
            aria-description="Opens the workout"
            data-test="active-workout"
            @click="open"
        >
            <svg viewBox="0 0 24 24" class="size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <path d="M3 12h18M6 8v8M9 9v6M15 9v6M18 8v8" />
            </svg>
            <!-- Named by what it shows, so a voice command saying those words finds it (PWA-051).
                 The spaces keep the words apart in that name; the flex layout ignores them. -->
            <span class="sr-only">Workout in progress,</span>
            {{ ' ' }}
            <span class="min-w-0 flex-1 truncate text-left">
                <PendingMark v-if="pending" derived class="text-on-accent">{{ bar.text }}</PendingMark>
                <template v-else>{{ bar.text }}</template>
            </span>
            {{ ' ' }}
            <span>Open</span>
            <svg viewBox="0 0 24 24" class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" aria-hidden="true">
                <path d="m6 15 6-6 6 6" />
            </svg>
        </button>
    </div>
</template>
