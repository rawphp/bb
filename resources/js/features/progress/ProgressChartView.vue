<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import type { ProgressShowOutput } from '@/contracts/progress_show';
import { linePath, timeAxis, valueAxis } from './chartGeometry';

type Chart = NonNullable<ProgressShowOutput['chart']>;

// Daily weights and trend weight against the phase goal range (ANA-001), drawn
// from the server's series, with the optional waist line on its own scale (WT-021).
// One <svg>, legend included, so the share card can draw it into a picture.
// With `picked`, it marks that weigh-in day and asks for the weigh-in under the
// pointer (ANA-012); the share card passes neither, so its picture has no mark.
// The share card also passes `width`, the size it draws the chart at; without it
// the chart takes the width it is laid out at, so one unit is one CSS pixel and
// its 11-unit text stays 11 px on any card.
const props = withDefaults(defineProps<{ chart: Chart; plotHeight?: number; picked?: string | null; width?: number }>(), {
    plotHeight: 220,
    picked: null,
    width: undefined,
});
const emit = defineEmits<{ pick: [date: string] }>();

const svg = ref<SVGSVGElement | null>(null);
// The width before the browser reports one, and the share card's.
const laidOut = ref(340);
const width = computed(() => props.width ?? laidOut.value);

let resize: ResizeObserver | null = null;
onMounted(() => {
    if (props.width !== undefined || typeof ResizeObserver === 'undefined' || !svg.value) return;
    resize = new ResizeObserver(([entry]) => {
        // Hidden or not yet laid out: keep the last width.
        if (entry && entry.contentRect.width > 0) laidOut.value = entry.contentRect.width;
    });
    resize.observe(svg.value);
});
onBeforeUnmount(() => resize?.disconnect());

const LEFT = 4;
const right = computed(() => width.value - 30);
const TOP = 8;
// The waist has its own scale; padding keeps a centimetre from filling the chart's height.
const WAIST_PAD = 2;
const bottom = computed(() => props.plotHeight - 30);

// "31 Aug", as rp-clone's axis reads (en-GB would say "Sept").
const day = new Intl.DateTimeFormat('en-US', { day: 'numeric', timeZone: 'UTC' });
const month = new Intl.DateTimeFormat('en-US', { month: 'short', timeZone: 'UTC' });
const dayLabel = (date: string) => `${day.format(new Date(date))} ${month.format(new Date(date))}`;

const time = computed(() => {
    const c = props.chart;
    return timeAxis([...c.weights, ...c.trend, ...c.goal, ...c.waist].map((p) => p.date), LEFT, right.value);
});

const weight = computed(() => {
    const c = props.chart;
    const values = [...c.weights.map((p) => p.value), ...c.trend.map((p) => p.value), ...c.goal.flatMap((p) => [p.low, p.high])];
    return valueAxis(values, bottom.value, TOP);
});

const waist = computed(() => (props.chart.waist.length > 1 ? valueAxis(props.chart.waist.map((p) => p.value), bottom.value, TOP, WAIST_PAD) : null));

const shapes = computed(() => {
    const { x } = time.value;
    const { y } = weight.value;
    const c = props.chart;
    const high = c.goal.map((p) => ({ x: x(p.date), y: y(p.high) }));
    const low = c.goal.map((p) => ({ x: x(p.date), y: y(p.low) })).reverse();
    return {
        range: high.length > 1 ? `${linePath([...high, ...low])}Z` : '',
        goal: linePath(c.goal.map((p) => ({ x: x(p.date), y: y(p.goal) }))),
        trend: linePath(c.trend.map((p) => ({ x: x(p.date), y: y(p.value) }))),
        weights: c.weights.map((p) => ({ key: p.date, x: x(p.date), y: y(p.value) })),
        waist: waist.value ? linePath(c.waist.map((p) => ({ x: x(p.date), y: waist.value!.y(p.value) }))) : '',
    };
});

// The picked day's guide, weigh-in dot and trend ring, in chart units.
const mark = computed(() => {
    if (!props.picked) return null;
    const dot = shapes.value.weights.find((p) => p.key === props.picked);
    const trend = props.chart.trend.find((p) => p.date === props.picked);
    if (!dot) return null;
    return { x: dot.x, y: dot.y, trendY: trend ? weight.value.y(trend.value) : null };
});

// The weigh-in dot nearest the pointer along x. Drawing only: which dot sits under a finger.
let pressed = false;

function pickAt(clientX: number): void {
    const box = svg.value?.getBoundingClientRect();
    const dots = shapes.value.weights;
    if (!box || box.width === 0 || dots.length === 0) return;
    const x = ((clientX - box.left) / box.width) * width.value;
    const nearest = dots.reduce((best, p) => (Math.abs(p.x - x) < Math.abs(best.x - x) ? p : best));
    if (nearest.key !== props.picked) emit('pick', nearest.key);
}

function onDown(event: PointerEvent): void {
    pressed = true;
    pickAt(event.clientX);
    // Keeps the drag picking when the finger leaves the chart; a pointer that is no longer down throws.
    try {
        svg.value?.setPointerCapture?.(event.pointerId);
    } catch {
        // The pick above still stands.
    }
}

function onMove(event: PointerEvent): void {
    if (pressed || event.pointerType === 'mouse') pickAt(event.clientX);
}

function onUp(): void {
    pressed = false;
}

const legend = computed(() => {
    const entries = [
        { text: 'Daily weight', color: 'fill-secondary-text' },
        { text: 'Trend', color: 'fill-accent' },
        { text: 'Goal range', color: 'fill-tone-good' },
        ...(waist.value ? [{ text: `Waist (${props.chart.waist_unit})`, color: 'fill-tone-info' }] : []),
    ];
    let x = LEFT + 4;
    return entries.map((entry) => {
        const at = x;
        x += 26 + entry.text.length * 5.6;
        return { ...entry, x: at };
    });
});
</script>

<template>
    <svg
        ref="svg"
        role="img"
        :aria-label="chart.accessibility_label"
        :viewBox="`0 0 ${width} ${plotHeight + 24}`"
        :width="props.width"
        class="block w-full font-sans"
        xmlns="http://www.w3.org/2000/svg"
        @pointerdown="onDown"
        @pointermove="onMove"
        @pointerup="onUp"
        @pointercancel="onUp"
    >
        <g aria-hidden="true">
            <g v-for="tick in weight.ticks" :key="tick">
                <line :x1="LEFT" :x2="right" :y1="weight.y(tick)" :y2="weight.y(tick)" class="stroke-secondary-text/30" stroke-width="0.5" />
                <text :x="right + 6" :y="weight.y(tick) + 4" class="fill-secondary-text" font-size="11">{{ tick }}</text>
            </g>
            <g v-for="tick in time.ticks" :key="tick">
                <line
                    :x1="time.x(tick)"
                    :x2="time.x(tick)"
                    :y1="TOP"
                    :y2="bottom + 18"
                    class="stroke-secondary-text/30"
                    stroke-width="0.5"
                    stroke-dasharray="2 2"
                />
                <text :x="time.x(tick) + 3" :y="bottom + 16" class="fill-secondary-text" font-size="11">
                    {{ dayLabel(tick) }}
                </text>
            </g>

            <path v-if="shapes.range" data-goal-range :d="shapes.range" class="fill-tone-good" fill-opacity="0.18" />
            <path :d="shapes.goal" class="stroke-tone-good" fill="none" stroke-width="1.5" stroke-dasharray="4 3" />
            <path v-if="shapes.waist" data-waist :d="shapes.waist" class="stroke-tone-info" fill="none" stroke-width="1.25" stroke-opacity="0.8" />
            <circle v-for="p in shapes.weights" :key="p.key" data-weight :cx="p.x" :cy="p.y" r="2.6" class="fill-secondary-text" fill-opacity="0.7" />
            <path data-trend :d="shapes.trend" class="stroke-accent" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />

            <!-- ANA-012: the picked day. It slides to a new day by transform (PWA-021). -->
            <g v-if="mark" data-picked class="pointer-events-none">
                <g class="transition-transform duration-150 ease-out motion-reduce:transition-none" :style="{ transform: `translateX(${mark.x}px)` }">
                    <line :x1="0" :x2="0" :y1="TOP" :y2="bottom" class="stroke-primary-text/40" stroke-width="1" />
                </g>
                <g
                    v-if="mark.trendY !== null"
                    data-picked-trend
                    class="transition-transform duration-150 ease-out motion-reduce:transition-none"
                    :style="{ transform: `translate(${mark.x}px, ${mark.trendY}px)` }"
                >
                    <circle r="4.5" class="fill-surface stroke-accent" stroke-width="2.5" />
                </g>
                <g class="transition-transform duration-150 ease-out motion-reduce:transition-none" :style="{ transform: `translate(${mark.x}px, ${mark.y}px)` }">
                    <circle r="4" class="fill-primary-text stroke-surface" stroke-width="1.5" />
                </g>
            </g>

            <g v-for="entry in legend" :key="entry.text">
                <circle :cx="entry.x + 4" :cy="plotHeight + 12" r="4" :class="entry.color" />
                <text :x="entry.x + 12" :y="plotHeight + 16" class="fill-secondary-text" font-size="11">{{ entry.text }}</text>
            </g>
        </g>
    </svg>
</template>
