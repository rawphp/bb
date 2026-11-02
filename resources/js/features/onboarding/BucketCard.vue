<script setup lang="ts">
// One body-fat bucket as a picture card (TBL-001). Ported from rp-clone's BodyFatStep.
// PLACEHOLDER ART, as in rp-clone: a standing figure drawn wider for each bucket. The
// real male and female photos replace it; the labels and the selection do not change.
defineProps<{ title: string; sex: 'male' | 'female'; index: number; selected: boolean; disabled?: boolean }>();

const emit = defineEmits<{ select: [] }>();
</script>

<template>
    <button
        type="button"
        :aria-label="`Body fat ${title}`"
        :aria-pressed="selected"
        :disabled="disabled"
        class="relative flex min-h-tap w-full flex-col items-center gap-2 rounded-xl bg-background p-3 active:opacity-70 disabled:opacity-60"
        :class="selected ? 'ring-3 ring-inset ring-accent' : 'ring-1 ring-inset ring-secondary-text/25'"
        @click="emit('select')"
    >
        <svg
            viewBox="0 0 48 96"
            class="h-24 text-secondary-text"
            :style="{ transform: `scaleX(${1 + 0.18 * index})` }"
            fill="currentColor"
            aria-hidden="true"
        >
            <circle cx="24" cy="10" r="8" />
            <path v-if="sex === 'male'" d="M14 22h20a6 6 0 0 1 6 6v24a3 3 0 0 1-6 0V32h-1v58a4 4 0 0 1-8 0V58h-2v32a4 4 0 0 1-8 0V32h-1v20a3 3 0 0 1-6 0V28a6 6 0 0 1 6-6z" />
            <path v-else d="M15 22h18a6 6 0 0 1 6 6v22a3 3 0 0 1-6 0V32l6 30H29v28a3.5 3.5 0 0 1-7 0V62h-1v28a3.5 3.5 0 0 1-7 0V62H9l6-30v18a3 3 0 0 1-6 0V28a6 6 0 0 1 6-6z" />
        </svg>
        <span class="font-semibold text-primary-text">{{ title }}</span>
        <svg v-if="selected" viewBox="0 0 24 24" class="absolute right-1.5 top-1.5 size-6 text-accent" aria-hidden="true">
            <circle cx="12" cy="12" r="10" fill="currentColor" />
            <path d="M7.5 12.5l3 3 6-6.5" fill="none" class="stroke-on-accent" stroke-width="2.25" />
        </svg>
    </button>
</template>
