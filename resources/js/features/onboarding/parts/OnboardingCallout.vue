<script setup lang="ts">
import { toneIcons } from '@/design/toneIcons';
import { toneClasses } from '@/design/tones';

// A coloured note with an icon, so the tone is never colour alone (UI-030).
// The server picks the tone (CAP-018). Ported from rp-clone's OnboardingParts.
withDefaults(defineProps<{ text: string; tone?: keyof typeof toneClasses }>(), { tone: 'info' });
</script>

<template>
    <p class="flex items-start gap-2 rounded-xl p-3 text-sm" :class="toneClasses[tone]">
        <svg viewBox="0 0 24 24" class="mt-px size-4 shrink-0" fill="currentColor" aria-hidden="true">
            <path :d="toneIcons[tone]" />
        </svg>
        <span>{{ text }}</span>
    </p>
</template>
