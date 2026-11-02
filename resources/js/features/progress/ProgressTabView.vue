<script setup lang="ts">
import { onMounted, ref } from 'vue';
import type { ProgressShowOutput } from '@/contracts/progress_show';
import ProgressScreen from './ProgressScreen.vue';
import { screenRead, type ReadFailure } from '@/app/screenRead';

// The Progress tab's root: reads progress_show for the picked scope and hands it to
// ProgressScreen. Given a payload (/dev/screens/progress_show), it shows that first.
const props = defineProps<{ payload?: ProgressShowOutput; pending?: boolean }>();

const progress = ref<ProgressShowOutput | undefined>(props.payload);
const failure = ref<ReadFailure | null>(null);
// A picked scope that did not load, said over the progress still shown.
const scopeError = ref<string | null>(null);

const load = screenRead<ProgressShowOutput>(
    'progress_show',
    'Progress could not be loaded.',
    (data) => {
        failure.value = null;
        scopeError.value = null;
        progress.value = data;
    },
    (why, hadData) => {
        // Another scope's progress may stay, unless this load showed a copy that must go
        // (OFF-003) or the user is signed out.
        if (progress.value && !hadData && !why.signedOut) {
            scopeError.value = why.offline ? 'Progress needs a connection.' : why.error;
            return;
        }
        progress.value = undefined;
        scopeError.value = null;
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
        :scope-error="scopeError"
        @scope="(scope) => load({ scope })"
    />
</template>
