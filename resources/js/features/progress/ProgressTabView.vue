<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { read } from '@/app/call';
import type { ProgressShowInput, ProgressShowOutput } from '@/contracts/progress_show';
import ProgressScreen from './ProgressScreen.vue';
import { readFailure, type ReadFailure } from './readFailure';

// The Progress tab's root: reads progress_show for the picked scope and hands it to
// ProgressScreen. Given a payload (/dev/screens/progress_show), it shows that first.
const props = defineProps<{ payload?: ProgressShowOutput; pending?: boolean }>();

const progress = ref<ProgressShowOutput | undefined>(props.payload);
const failure = ref<ReadFailure | null>(null);
// Each load counts up, so an answer to an older scope never replaces a newer one.
let latest = 0;

async function load(input: ProgressShowInput): Promise<void> {
    const ticket = ++latest;
    let settled = false;
    const fail = (error: unknown) => {
        settled = true;
        if (ticket !== latest) return;
        progress.value = undefined;
        failure.value = readFailure(error, 'Progress could not be loaded.');
    };
    const show = (data: ProgressShowOutput) => {
        settled = true;
        if (ticket !== latest) return;
        failure.value = null;
        progress.value = data;
    };

    try {
        // A cached copy shows at once; the refresh replaces it, or takes it away when it fails (OFF-003).
        const data = await read<ProgressShowOutput>('progress_show', { ...input }, show, fail);
        // A refresh or failure that already landed wins over the copy read() was handed.
        if (!settled) show(data);
    } catch (error) {
        fail(error);
    }
}

onMounted(() => {
    if (!progress.value) void load({});
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
