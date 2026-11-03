<script setup lang="ts">
// The first setup screen with the Coach on (AGT-030): the stepper, or the Coach chat. Both
// save the same answers and end in the same Start my plan, so they make the same plan.
const emit = defineEmits<{ pick: [door: 'stepper' | 'chat'] }>();

const doors = [
    { door: 'stepper', title: 'Set up step by step', detail: 'Answer one short screen at a time.' },
    { door: 'chat', title: 'Talk it through with the Coach', detail: 'Tell the Coach about yourself, and it fills in the steps with you.' },
] as const;
</script>

<template>
    <div class="flex min-h-[70dvh] flex-col justify-center gap-6">
        <div class="flex flex-col gap-2 text-center">
            <h2 class="text-2xl font-bold text-primary-text">Set up your plan</h2>
            <p class="text-secondary-text">Either way you get the same plan, and you can switch to the steps at any time.</p>
        </div>
        <div class="flex flex-col gap-3">
            <button
                v-for="choice in doors"
                :key="choice.door"
                type="button"
                data-test="door"
                class="flex min-h-tap flex-col gap-1 rounded-xl bg-surface p-4 text-left active:opacity-70"
                @click="emit('pick', choice.door)"
            >
                <span class="font-semibold text-primary-text">{{ choice.title }}</span>
                <span class="text-sm text-secondary-text">{{ choice.detail }}</span>
            </button>
        </div>
    </div>
</template>
