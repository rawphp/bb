<script setup lang="ts">
import type { Component } from 'vue';

// Every design system component's gallery file (VIEW-004).
const entries = Object.entries(
    import.meta.glob<Component>('/resources/js/design/gallery/*.gallery.vue', { eager: true, import: 'default' }),
)
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([path, component]) => ({ name: path.split('/').pop()!.replace('.gallery.vue', ''), component }));
</script>

<template>
    <main class="p-6">
        <h1 class="text-lg font-semibold">Gallery</h1>
        <p v-if="entries.length === 0" class="mt-4">No components yet.</p>
        <section v-for="entry in entries" :key="entry.name" class="mt-6">
            <h2 class="font-semibold">{{ entry.name }}</h2>
            <component :is="entry.component" />
        </section>
    </main>
</template>
