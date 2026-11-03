<script setup lang="ts">
// One weekday's step range in the schedule defaults: two number fields, both empty for
// none. Ported from rp-clone's StepRangeRow. It shows what is typed and checks nothing.
withDefaults(defineProps<{ weekday: number; label: string; min: string; max: string; disabled?: boolean }>(), { disabled: false });

const emit = defineEmits<{ 'update:min': [value: string]; 'update:max': [value: string] }>();

const field = 'w-20 rounded-lg bg-background px-3 py-1.5 text-right tabular-nums text-primary-text placeholder:text-secondary-text disabled:opacity-60';
</script>

<template>
    <div class="flex min-h-tap items-center justify-between gap-3 px-4 py-2">
        <span>{{ label }}</span>
        <span class="flex items-center gap-2">
            <input
                type="text"
                inputmode="numeric"
                placeholder="min"
                :aria-label="`${label} steps, at least`"
                :data-test="`steps-min-${weekday}`"
                :value="min"
                :disabled="disabled"
                :class="field"
                @input="emit('update:min', ($event.target as HTMLInputElement).value)"
            />
            <span class="text-secondary-text">to</span>
            <input
                type="text"
                inputmode="numeric"
                placeholder="max"
                :aria-label="`${label} steps, at most`"
                :data-test="`steps-max-${weekday}`"
                :value="max"
                :disabled="disabled"
                :class="field"
                @input="emit('update:max', ($event.target as HTMLInputElement).value)"
            />
        </span>
    </div>
</template>
