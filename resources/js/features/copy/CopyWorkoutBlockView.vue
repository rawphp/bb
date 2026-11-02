<script setup lang="ts">
import type { CopyShowOutput } from '@/contracts/copy_show';
import { toneClasses } from '@/design/tones';

// The day's workout between its meals (MEAL-060), so the user sees where each meal falls.
defineProps<{ block: CopyShowOutput['workouts'][number] }>();
</script>

<template>
    <div data-kind="workout" class="flex items-center gap-2 rounded-xl p-2.5 text-sm font-semibold" :class="toneClasses.info">
        <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4 shrink-0" fill="currentColor">
            <path d="M1 10h2V7h3v10H3v-3H1zm22 0h-2V7h-3v10h3v-3h2zM7 5h3v14H7zm7 0h3v14h-3zm-4 6h4v2h-4z" />
        </svg>
        <span>{{ block.name }} · {{ block.time_text }}</span>
    </div>
</template>
