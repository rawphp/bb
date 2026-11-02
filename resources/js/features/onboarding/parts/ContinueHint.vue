<script setup lang="ts">
// Says why Next does nothing yet, in the server's words (UI-007). StepFlow shows the
// step's own hint; this is for a hint inside a step's content. Ported from rp-clone's
// OnboardingParts.
defineProps<{ text?: string | null }>();
</script>

<template>
    <p v-if="text" class="text-sm text-secondary-text">{{ text }}</p>
</template>
