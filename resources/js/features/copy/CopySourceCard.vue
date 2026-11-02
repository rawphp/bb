<script setup lang="ts">
import type { CopyShowOutput } from '@/contracts/copy_show';
import StatusPill from '@/design/StatusPill.vue';

// The meal or day copied from: its name, time and foods, with the server's status pill (MEAL-060).
defineProps<{ source: CopyShowOutput['source'] }>();
</script>

<template>
    <section class="space-y-1.5 rounded-2xl bg-background p-3.5" data-test="source">
        <div class="flex items-center gap-2">
            <h3 class="font-semibold text-primary-text">From {{ source.name }}</h3>
            <span v-if="source.time_text" class="text-sm text-secondary-text">{{ source.time_text }}</span>
            <StatusPill v-if="source.status" class="ml-auto" :text="source.status.text" :tone="source.status.tone" />
        </div>
        <p class="text-sm text-secondary-text">{{ source.foods_text }}</p>
    </section>
</template>
