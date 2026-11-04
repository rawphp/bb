<script setup lang="ts">
import type { StepFlowMark } from './StepFlow.vue';

// The list of a step flow's steps (ONB-022): StepFlow draws it beside the step on a wide
// screen and in its sheet on a phone. A mark the server says can open is a button that
// goes back to it; the current one is marked as the step; the rest are text.
const props = withDefaults(defineProps<{ marks: StepFlowMark[]; disabled?: boolean }>(), { disabled: false });

const emit = defineEmits<{ open: [step: number] }>();

const tone = {
    done: 'text-primary-text',
    current: 'bg-accent/10 font-semibold text-accent',
    todo: 'text-secondary-text',
} as const;
</script>

<template>
    <ol class="flex flex-col">
        <li v-for="mark in props.marks" :key="mark.step">
            <component
                :is="mark.can_open ? 'button' : 'span'"
                :type="mark.can_open ? 'button' : undefined"
                class="flex min-h-tap w-full items-center gap-3 rounded-xl px-2 text-left"
                :class="[tone[mark.state], { 'hover:bg-accent/10 active:opacity-70 disabled:opacity-50': mark.can_open }]"
                :disabled="mark.can_open ? props.disabled : undefined"
                :aria-label="mark.can_open ? `${mark.label}, done. Go back to this step` : undefined"
                :aria-current="mark.state === 'current' ? 'step' : undefined"
                @click="mark.can_open && emit('open', mark.step)"
            >
                <span
                    class="flex size-5 shrink-0 items-center justify-center rounded-full"
                    :class="{ 'bg-accent text-on-accent': mark.state === 'done', 'border-2 border-accent': mark.state === 'current', 'border-2 border-secondary-text/40': mark.state === 'todo' }"
                    aria-hidden="true"
                >
                    <svg v-if="mark.state === 'done'" viewBox="0 0 24 24" class="size-3.5" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 12.5l4.5 4.5L19 7.5" />
                    </svg>
                    <span v-else-if="mark.state === 'current'" class="size-2 rounded-full bg-accent" />
                </span>
                <span class="flex-1">{{ mark.label }}</span>
            </component>
        </li>
    </ol>
</template>
