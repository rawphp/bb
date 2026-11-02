<script setup lang="ts">
import { computed, ref, watchEffect } from 'vue';
import { featureRoutes } from '@/app/router';
import { findScreen } from './screens';

// Renders a capability screen from tests/fixtures/screens/<name>.json, or
// one of its other states from <name>.<state>.json. ?view=<route name> picks
// the screen when several read the same capability.
const props = defineProps<{ name: string; view?: string }>();

const fixtures = import.meta.glob<unknown>('/tests/fixtures/screens/*.json', { import: 'default' });

const screen = computed(() => findScreen(props.name.replace(/\..*$/, ''), featureRoutes, props.view));
const payload = ref<unknown>();
const missing = ref(false);

watchEffect(async () => {
    const load = fixtures[`/tests/fixtures/screens/${props.name}.json`];
    payload.value = undefined;
    missing.value = load === undefined;
    if (load) payload.value = await load();
});
</script>

<template>
    <p v-if="!screen" class="p-6">No screen renders {{ name }}.</p>
    <p v-else-if="missing" class="p-6">No fixture at tests/fixtures/screens/{{ name }}.json.</p>
    <component :is="screen" v-else-if="payload !== undefined" :payload="payload" />
</template>
