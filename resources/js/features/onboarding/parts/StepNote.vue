<script setup lang="ts">
// Small grey explanatory text. Ported from rp-clone's OnboardingParts.
defineProps<{ text: string }>();
</script>

<template>
    <p class="text-sm text-secondary-text">{{ text }}</p>
</template>
