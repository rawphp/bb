<script setup lang="ts">
// A bold sub-heading inside a step. Ported from rp-clone's OnboardingParts.
defineProps<{ text: string }>();
</script>

<template>
    <h3 class="text-base font-semibold text-primary-text">{{ text }}</h3>
</template>
