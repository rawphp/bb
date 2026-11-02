<script setup lang="ts">
import { useId } from 'vue';
import PriorityBarsView from './PriorityBarsView.vue';
import type { Priority, PriorityOption } from './types';

// A muscle with its priority bars and a picker to change the priority (MESO-020, MESO-032).
const props = withDefaults(
    defineProps<{
        muscleTitle: string;
        priority: Priority;
        priorityTitle: string;
        bars: number;
        options: PriorityOption[];
        disabled?: boolean;
    }>(),
    { disabled: false },
);

const emit = defineEmits<{ change: [priority: Priority] }>();
const id = useId();

// The select always shows the server's priority: it goes back to it at once, and moves
// only when the saved priority comes back. A failed save so leaves nothing picked that
// was never saved, and picking the same priority again sends it again.
function choose(event: Event): void {
    const select = event.target as HTMLSelectElement;
    const option = props.options[select.selectedIndex];
    select.selectedIndex = props.options.findIndex((o) => o.priority === props.priority);
    if (option && option.priority !== props.priority) emit('change', option.priority);
}
</script>

<template>
    <div class="flex min-h-tap items-center gap-3">
        <label :for="id" class="flex-1 text-primary-text">{{ muscleTitle }}</label>
        <PriorityBarsView :bars="bars" />
        <span class="relative flex min-h-tap min-w-27 items-center justify-end gap-1 text-accent">
            <select
                :id="id"
                class="absolute inset-0 cursor-pointer opacity-0 disabled:cursor-default"
                :aria-label="`${muscleTitle} priority`"
                :disabled="disabled"
                @change="choose"
            >
                <option v-for="option in options" :key="option.title" :selected="option.priority === priority">{{ option.title }}</option>
            </select>
            <span aria-hidden="true" :class="{ 'opacity-50': disabled }">{{ priorityTitle }}</span>
            <svg viewBox="0 0 12 16" class="size-3" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 6l3-3 3 3M3 10l3 3 3-3" />
            </svg>
        </span>
    </div>
</template>
