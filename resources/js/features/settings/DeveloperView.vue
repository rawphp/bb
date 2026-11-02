<script setup lang="ts">
import DeveloperSection from './DeveloperSection.vue';

// Settings → Developer (SET-011) as its own page, so the time-walk clock is reachable
// before the Settings list mounts DeveloperSection itself. The route is local and testing only.
</script>

<template>
    <main class="bb-screen-content space-y-4">
        <h1 class="text-center text-lg font-semibold">Developer</h1>
        <div class="rounded-2xl bg-surface p-4">
            <DeveloperSection />
        </div>
    </main>
</template>
