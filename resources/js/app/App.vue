<script setup lang="ts">
import { useRoute } from 'vue-router';
import AppShell from './AppShell.vue';

const route = useRoute();
</script>

<template>
    <!-- The page itself never scrolls (shell.css), so a screen outside the shell scrolls in its own box. -->
    <div v-if="route.meta.shell === false" class="bb-bare">
        <RouterView />
    </div>
    <AppShell v-else />
</template>
