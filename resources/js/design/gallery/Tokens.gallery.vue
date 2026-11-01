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

const text = ['primary-text', 'secondary-text', 'accent'];
const macros = ['calories', 'protein', 'fat', 'carbs'];
const tones = ['good', 'neutral', 'warning', 'info'];
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

        <div v-for="ground in ['background', 'surface']" :key="ground" class="rounded-lg p-4" :style="{ background: `var(--bb-${ground})` }">
            <p v-for="name in text" :key="name" :style="{ color: `var(--bb-${name})` }">{{ name }} on {{ ground }}</p>
            <p v-for="macro in macros" :key="macro" class="font-semibold" :style="{ color: `var(--bb-macro-${macro})` }">
                {{ macro }}
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <span class="min-h-tap inline-flex items-center rounded-lg bg-accent px-4 text-on-accent">on-accent</span>
            <span
                v-for="tone in tones"
                :key="tone"
                class="inline-flex items-center rounded-full px-3 py-1 text-sm font-semibold"
                :style="{ color: `var(--bb-tone-${tone})`, background: `var(--bb-tone-${tone}-bg)` }"
            >
                {{ tone }}
            </span>
        </div>
    </div>
</template>
