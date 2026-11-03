<script setup lang="ts">
import ReadState from '@/app/ReadState.vue';
import { useRead } from '@/app/useRead';
import type { SettingsShowOutput } from '@/contracts/settings_show';
import SettingsContent from './SettingsContent.vue';

// Settings (SET-001 to SET-011), reached from More. settings_show sends every row's
// value, choices and status; SettingsContent shows them and sends each change.
const props = defineProps<{ payload?: SettingsShowOutput }>();

const { data: settings, failure, load } = useRead<SettingsShowOutput>('settings_show', {}, 'Your settings could not be loaded.', props.payload);
</script>

<template>
    <main class="bb-screen-content space-y-4">
        <h1 class="text-center text-lg font-semibold">Settings</h1>
        <SettingsContent v-if="settings" :settings="settings" :load="load" />
        <ReadState v-else :failure="failure" what="Your settings" @retry="load" />
    </main>
</template>
