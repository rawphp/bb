<script setup lang="ts">
import { computed, ref, watchEffect } from 'vue';
import { featureRoutes } from '@/app/router';
import { findScreen, routeProps as propsOf, takesFixture } from './screens';

// Renders a capability screen from tests/fixtures/screens/<name>.json, or
// one of its other states from <name>.<state>.json. A screen that reads nothing
// (meta.fixture false) renders with no payload. ?view=<route name> picks the
// screen when several read the same capability; any other single-valued query key is
// passed to the screen as a string prop, over any its route sets itself.
const props = defineProps<{ name: string; view?: string; screenProps?: Record<string, string> }>();

const fixtures = import.meta.glob<unknown>('/tests/fixtures/screens/*.json', { import: 'default' });

const screenName = computed(() => props.name.replace(/\..*$/, ''));
const screen = computed(() => findScreen(screenName.value, featureRoutes, props.view));
// The props the screen's own route sets; the query's keys go over them.
const routeProps = computed(() => propsOf(screenName.value, featureRoutes, props.view));
const fixtureless = computed(() => !takesFixture(screenName.value, featureRoutes, props.view));
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
    <component :is="screen" v-else-if="fixtureless" v-bind="{ ...routeProps, ...screenProps }" />
    <p v-else-if="missing" class="p-6">No fixture at tests/fixtures/screens/{{ name }}.json.</p>
    <component :is="screen" v-else-if="payload !== undefined" v-bind="{ ...routeProps, ...screenProps }" :payload="payload" />
</template>
