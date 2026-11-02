<script setup lang="ts">
// Stands in for a tab's root screen until its feature declares a route at the tab's path.
defineProps<{ label: string }>();
</script>

<template>
    <main class="bb-screen-content">
        <h1 class="text-2xl font-bold">{{ label }}</h1>
    </main>
</template>
