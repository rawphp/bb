<script setup lang="ts">
import { computed, ref, watchEffect } from 'vue';
import { featureRoutes } from '@/app/router';
import { findScreen, routeProps as propsOf, screenFixtures, takesFixture } from './screens';

// Renders a capability screen from tests/fixtures/screens/<name>.json, or
// one of its other states from <name>.<state>.json. A screen that shows a second read
// (meta.fixtures, prop → read) gets that read's fixture in the same state, or its main
// one when it has none in that state. A screen that reads nothing
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
const others = ref<Record<string, unknown>>({});
const missing = ref(false);

function fixture(name: string) {
    return fixtures[`/tests/fixtures/screens/${name}.json`];
}

watchEffect(async () => {
    const load = fixture(props.name);
    const state = props.name.includes('.') ? props.name.slice(props.name.indexOf('.')) : '';
    const second = Object.entries(screenFixtures(screenName.value, featureRoutes, props.view));
    payload.value = undefined;
    missing.value = load === undefined;
    if (!load) return;
    const loaded = await Promise.all(second.map(async ([prop, read]) => [prop, await (fixture(`${read}${state}`) ?? fixture(read))?.()] as const));
    others.value = Object.fromEntries(loaded);
    payload.value = await load();
});
</script>

<template>
    <p v-if="!screen" class="p-6">No screen renders {{ name }}.</p>
    <component :is="screen" v-else-if="fixtureless" v-bind="{ ...routeProps, ...screenProps }" />
    <p v-else-if="missing" class="p-6">No fixture at tests/fixtures/screens/{{ name }}.json.</p>
    <component :is="screen" v-else-if="payload !== undefined" v-bind="{ ...routeProps, ...others, ...screenProps }" :payload="payload" />
</template>
