<script setup lang="ts">
import { onMounted, ref } from 'vue';
import type { ProgressShowOutput } from '@/contracts/progress_show';
import ProgressScreen from './ProgressScreen.vue';
import { screenRead, type ReadFailure } from './screenRead';

// The Progress tab's root: reads progress_show for the picked scope and hands it to
// ProgressScreen. Given a payload (/dev/screens/progress_show), it shows that first.
const props = defineProps<{ payload?: ProgressShowOutput; pending?: boolean }>();

const progress = ref<ProgressShowOutput | undefined>(props.payload);
const failure = ref<ReadFailure | null>(null);

const load = screenRead<ProgressShowOutput>(
    'progress_show',
    'Progress could not be loaded.',
    (data) => {
        failure.value = null;
        progress.value = data;
    },
    (why) => {
        progress.value = undefined;
        failure.value = why;
    },
);

onMounted(() => {
    if (!progress.value) void load();
});
</script>

<template>
    <ProgressScreen
        :payload="progress"
        :error="failure?.error"
        :offline="failure?.offline"
        :pending="pending"
        @scope="(scope) => load({ scope })"
    />
</template>
