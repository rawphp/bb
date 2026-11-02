<script setup lang="ts">
// A rounded panel for one repeated item inside a step (a training day, a busy period).
// Ported from rp-clone's OnboardingParts.
</script>

<template>
    <div class="flex flex-col gap-2 rounded-xl bg-background p-3">
        <slot />
    </div>
</template>
