<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { ProgressShowOutput } from '@/contracts/progress_show';
import { toneText } from '@/design/toneText';
import ProgressChartView from './ProgressChartView.vue';

type Chart = NonNullable<ProgressShowOutput['chart']>;

// ANA-012: the weight chart with a readout over it. Touch or drag across the chart,
// hover it, or use the arrow keys, and the readout shows that weigh-in day as the
// server words it. Until then it shows the latest weigh-in.
const props = defineProps<{ chart: Chart }>();

// Null reads out the latest weigh-in; a new chart (the other scope) starts there again.
const index = ref<number | null>(null);
watch(
    () => props.chart,
    () => (index.value = null),
);

const count = computed(() => props.chart.readouts.length);
const at = computed(() => index.value ?? count.value - 1);
const readout = computed(() => props.chart.readouts[at.value] ?? null);
const spoken = computed(() =>
    readout.value ? `${readout.value.title}: ${readout.value.lines.map((line) => `${line.label} ${line.value}`).join(', ')}` : undefined,
);

function pick(date: string): void {
    const found = props.chart.readouts.findIndex((r) => r.date === date);
    if (found !== -1) index.value = found;
}

const keys: Record<string, (i: number) => number> = {
    ArrowLeft: (i) => i - 1,
    ArrowDown: (i) => i - 1,
    ArrowRight: (i) => i + 1,
    ArrowUp: (i) => i + 1,
    Home: () => 0,
    End: () => count.value - 1,
};

function onKey(event: KeyboardEvent): void {
    const step = keys[event.key];
    if (!step || count.value === 0) return;
    event.preventDefault();
    index.value = Math.min(count.value - 1, Math.max(0, step(at.value)));
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <div v-if="readout" data-readout class="flex flex-col gap-1 px-1">
            <p data-readout-title class="text-sm font-semibold">{{ readout.title }}</p>
            <dl class="flex flex-wrap gap-x-5 gap-y-1">
                <div v-for="line in readout.lines" :key="line.label" data-readout-line class="flex flex-col">
                    <dt class="text-xs text-secondary-text">{{ line.label }}</dt>
                    <dd class="font-semibold tabular-nums" :class="toneText[line.tone]">{{ line.value }}</dd>
                </div>
            </dl>
        </div>
        <div
            v-if="readout"
            role="slider"
            tabindex="0"
            :aria-label="chart.accessibility_label"
            aria-valuemin="1"
            :aria-valuemax="count"
            :aria-valuenow="at + 1"
            :aria-valuetext="spoken"
            class="cursor-crosshair touch-pan-y rounded-lg select-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            @keydown="onKey"
        >
            <ProgressChartView :chart="chart" :picked="readout.date" @pick="pick" />
        </div>
        <ProgressChartView v-else :chart="chart" />
    </div>
</template>
