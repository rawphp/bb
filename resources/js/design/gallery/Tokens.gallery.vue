<script setup lang="ts">
import { ref } from 'vue';
import { setAppearance, storedAppearance, type Appearance } from '../appearance';

// Every colour token on its ground, with the appearance switch (SET-002, PWA-041).
const appearance = ref<Appearance>(storedAppearance());
const appearances: Appearance[] = ['system', 'light', 'dark'];

function choose(value: Appearance): void {
    appearance.value = value;
    setAppearance(value);
}

// Whole class names, so Tailwind finds every token utility in this file.
const grounds = [
    { name: 'background', class: 'bg-background' },
    { name: 'surface', class: 'bg-surface' },
];
const text = [
    { name: 'primary-text', class: 'text-primary-text' },
    { name: 'secondary-text', class: 'text-secondary-text' },
    { name: 'accent', class: 'text-accent' },
    { name: 'destructive', class: 'text-destructive' },
];
const macros = [
    { name: 'calories', class: 'text-macro-calories' },
    { name: 'protein', class: 'text-macro-protein' },
    { name: 'fat', class: 'text-macro-fat' },
    { name: 'carbs', class: 'text-macro-carbs' },
];
const tones = [
    { name: 'good', class: 'text-tone-good bg-tone-good-bg' },
    { name: 'neutral', class: 'text-tone-neutral bg-tone-neutral-bg' },
    { name: 'warning', class: 'text-tone-warning bg-tone-warning-bg' },
    { name: 'info', class: 'text-tone-info bg-tone-info-bg' },
];
</script>

<template>
    <div class="flex flex-col gap-4">
        <div role="radiogroup" aria-label="Appearance" class="flex gap-2">
            <button
                v-for="value in appearances"
                :key="value"
                type="button"
                role="radio"
                :aria-checked="appearance === value"
                class="min-h-tap rounded-lg px-4 capitalize"
                :class="appearance === value ? 'bg-accent text-on-accent' : 'bg-surface text-primary-text'"
                @click="choose(value)"
            >
                {{ value }}
            </button>
        </div>

        <div v-for="ground in grounds" :key="ground.name" class="rounded-lg p-4" :class="ground.class">
            <p v-for="t in text" :key="t.name" :class="t.class">{{ t.name }} on {{ ground.name }}</p>
            <p v-for="macro in macros" :key="macro.name" class="font-semibold" :class="macro.class">
                {{ macro.name }}
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <span class="min-h-tap inline-flex items-center rounded-lg bg-accent px-4 text-on-accent">on-accent</span>
            <span
                v-for="tone in tones"
                :key="tone.name"
                class="inline-flex items-center rounded-full px-3 py-1 text-sm font-semibold"
                :class="tone.class"
            >
                {{ tone.name }}
            </span>
        </div>
    </div>
</template>
