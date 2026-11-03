<script setup lang="ts">
// A labelled on/off switch row, as a SwiftUI Toggle in a List. The caller's classes go on
// the row, so it keeps its own padding and background; every other attribute and listener
// goes on the checkbox itself. There is no local copy of the model: the box is patched
// only when the caller's value changes, so a screen that shows the server's answer after a
// refused write can set the box back from its own change listener and it stays there.
defineOptions({ inheritAttrs: false });
withDefaults(defineProps<{ label: string; modelValue: boolean; disabled?: boolean; busy?: boolean }>(), { disabled: false, busy: false });

const emit = defineEmits<{ 'update:modelValue': [on: boolean] }>();
</script>

<template>
    <label class="flex min-h-tap items-center justify-between gap-3" :class="$attrs.class">
        <span>{{ label }}</span>
        <input
            :checked="modelValue"
            @change="emit('update:modelValue', ($event.target as HTMLInputElement).checked)"
            v-bind="{ ...$attrs, class: undefined }"
            type="checkbox"
            role="switch"
            class="peer sr-only"
            :disabled="disabled"
            :aria-busy="busy"
        />
        <!-- The knob is white in both appearances, as an iOS switch's is; a surface-coloured knob would vanish on the dark track. -->
        <span
            aria-hidden="true"
            class="relative h-8 w-13 shrink-0 rounded-full bg-tone-neutral-bg transition-colors peer-checked:bg-accent peer-disabled:opacity-60 peer-focus-visible:ring-2 peer-focus-visible:ring-accent after:absolute after:top-0.5 after:left-0.5 after:size-7 after:rounded-full after:bg-white after:shadow after:transition-transform peer-checked:after:translate-x-5"
        />
    </label>
</template>
