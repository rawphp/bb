<script setup lang="ts">
import { computed } from 'vue';
import type { ProgressShowOutput } from '@/contracts/progress_show';
import ProgressChartView from './ProgressChartView.vue';

// The picture ANA-010 shares: title, phase summary, chart and figures. All SVG, so
// share.ts can draw it to a PNG, and always light (bb-light), so it reads the same wherever it lands.
const props = defineProps<{
    content: NonNullable<ProgressShowOutput['share']>;
    chart: NonNullable<ProgressShowOutput['chart']>;
    summary: ProgressShowOutput['summary'];
}>();

const WIDTH = 380;
const PAD = 20;
const PLOT = 200;
// SVG text does not wrap; break the message between words at about this many characters.
const LINE_CHARS = 42;

const lines = computed(() =>
    props.content.message.split(' ').reduce<string[]>((out, word) => {
        const last = out.at(-1);
        if (last !== undefined && `${last} ${word}`.length <= LINE_CHARS) out[out.length - 1] = `${last} ${word}`;
        else out.push(word);
        return out;
    }, []),
);

const chartTop = computed(() => 62 + lines.value.length * 18);
const figuresTop = computed(() => chartTop.value + PLOT + 24 + 18);
const height = computed(() => figuresTop.value + 50);
</script>

<template>
    <svg
        data-share-card
        :viewBox="`0 0 ${WIDTH} ${height}`"
        :width="WIDTH"
        :height="height"
        class="bb-light font-sans"
        xmlns="http://www.w3.org/2000/svg"
    >
        <rect :width="WIDTH" :height="height" class="fill-surface" />
        <text :x="PAD" :y="PAD + 22" class="fill-primary-text" font-size="22" font-weight="700">{{ content.title }}</text>
        <text v-for="(line, i) in lines" :key="i" :x="PAD" :y="PAD + 50 + i * 18" class="fill-secondary-text" font-size="14">{{ line }}</text>
        <ProgressChartView :chart="chart" :plot-height="PLOT" :x="PAD" :y="chartTop" :width="WIDTH - PAD * 2" :height="PLOT + 24" />
        <g v-for="(figure, i) in summary" :key="figure.label">
            <text :x="PAD + i * 114" :y="figuresTop" class="fill-secondary-text" font-size="13">{{ figure.label }}</text>
            <text :x="PAD + i * 114" :y="figuresTop + 24" class="fill-primary-text" font-size="18" font-weight="600">{{ figure.value }}</text>
        </g>
    </svg>
</template>
