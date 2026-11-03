<script setup lang="ts">
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';
import type { ActivityShowOutput } from '@/contracts/activity_show';
import StatusPill from '@/design/StatusPill.vue';

// Activity (CAP-022): the user's recent changes, newest first, with the channel each came
// through and what came of it. The server writes every word, the time included.
// /dev/screens/activity_show hands it a fixture as payload.
const props = defineProps<{ payload?: ActivityShowOutput }>();

const { data: activity, failure, load } = useRead<ActivityShowOutput>('activity_show', {}, 'Activity could not be loaded.', props.payload);
</script>

<template>
    <main class="bb-screen-content">
        <h1 class="mb-4 px-1 text-3xl font-bold">Activity</h1>

        <template v-if="activity">
            <p v-if="activity.entries.length === 0" class="rounded-2xl bg-surface px-4 py-3 text-secondary-text" data-test="empty">No changes yet.</p>
            <ul v-else class="divide-y divide-secondary-text/15 overflow-hidden rounded-2xl bg-surface">
                <li v-for="entry in activity.entries" :key="entry.id" class="flex flex-col items-start gap-1.5 px-4 py-3" data-test="entry">
                    <span class="font-medium text-primary-text">{{ entry.title }}</span>
                    <span class="text-sm text-secondary-text" data-test="channel">{{ entry.channel_label }} · {{ entry.when_text }}</span>
                    <StatusPill :text="entry.result.text" :tone="entry.result.tone" data-test="result" />
                </li>
            </ul>
        </template>

        <ReadState v-else :failure="failure" what="Activity" @retry="load" />
    </main>
</template>
