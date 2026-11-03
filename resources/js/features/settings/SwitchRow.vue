<script setup lang="ts">
// A labelled on/off switch row, as a SwiftUI Toggle in a List. Attributes such as
// data-test, checked and disabled go on the checkbox itself.
defineOptions({ inheritAttrs: false });
defineProps<{ label: string }>();
</script>

<template>
    <label class="flex min-h-tap items-center justify-between gap-3 px-4 py-2">
        <span>{{ label }}</span>
        <input type="checkbox" role="switch" class="peer sr-only" v-bind="$attrs" />
        <!-- The knob is white in both appearances, as an iOS switch's is; a surface-coloured knob would vanish on the dark track. -->
        <span
            aria-hidden="true"
            class="relative h-8 w-13 shrink-0 rounded-full bg-tone-neutral-bg transition-colors peer-checked:bg-accent peer-disabled:opacity-60 peer-focus-visible:ring-2 peer-focus-visible:ring-accent after:absolute after:top-0.5 after:left-0.5 after:size-7 after:rounded-full after:bg-white after:shadow after:transition-transform peer-checked:after:translate-x-5"
        />
    </label>
</template>
