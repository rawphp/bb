<script setup lang="ts">
import { toRef } from 'vue';
import { provideScreenTop } from './screenTop';

// One screen in TabStack: says whether it is on show (screenTop.ts).
const props = defineProps<{ top: boolean }>();

provideScreenTop(toRef(props, 'top'));
</script>

<template>
    <slot />
</template>
