<script setup lang="ts">
// One group of rows on a sheet, as a SwiftUI Form section: a header, the rows on a card, a note below.
defineProps<{ header?: string; footer?: string }>();
</script>

<template>
    <section class="flex flex-col gap-1.5">
        <h3 v-if="header" class="px-4 text-sm font-semibold text-secondary-text">{{ header }}</h3>
        <div class="divide-y divide-secondary-text/15 rounded-2xl bg-surface px-4"><slot /></div>
        <p v-if="footer" class="px-4 text-sm text-secondary-text">{{ footer }}</p>
    </section>
</template>
