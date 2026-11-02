<script setup lang="ts">
import { useRoute } from 'vue-router';
import AppShell from './AppShell.vue';

const route = useRoute();
</script>

<template>
    <RouterView v-if="route.meta.shell === false" />
    <AppShell v-else />
</template>
