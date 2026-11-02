<script setup lang="ts">
// Stands in for a tab's root screen until its feature declares a route at the tab's path.
// It links the screens that belong to the tab (meta.tab), so they can be reached meanwhile.
withDefaults(defineProps<{ label: string; links?: string[] }>(), { links: () => [] });
</script>

<template>
    <main class="bb-screen-content">
        <h1 class="text-2xl font-bold">{{ label }}</h1>
        <ul v-if="links.length" class="mt-4 space-y-2">
            <li v-for="link in links" :key="link">
                <RouterLink :to="link" class="underline">{{ link }}</RouterLink>
            </li>
        </ul>
    </main>
</template>
