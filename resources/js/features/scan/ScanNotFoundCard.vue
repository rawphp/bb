<script setup lang="ts">
// FOOD-041: a barcode the server does not know, in its words, and the two ways on.
defineProps<{ message: string; disabled?: boolean }>();
defineEmits<{ 'scan-label': []; create: [] }>();
</script>

<template>
    <section class="space-y-2.5 rounded-2xl bg-surface p-3.5" data-test="not-found">
        <p class="flex gap-2 text-sm text-primary-text">
            <svg viewBox="0 0 24 24" class="mt-px size-4 shrink-0 fill-none stroke-current stroke-2" aria-hidden="true">
                <circle cx="12" cy="12" r="9.5" />
                <path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .8-1 1.5v.7M12 17h.01" stroke-linecap="round" />
            </svg>
            {{ message }}
        </p>
        <button
            type="button"
            :disabled="disabled"
            class="min-h-tap w-full rounded-full bg-accent font-semibold text-on-accent active:opacity-70 disabled:opacity-50"
            data-test="scan-label"
            @click="$emit('scan-label')"
        >
            Scan the label
        </button>
        <button
            type="button"
            :disabled="disabled"
            class="min-h-tap w-full rounded-full bg-accent/15 font-semibold text-accent active:opacity-70 disabled:opacity-50"
            data-test="create-food"
            @click="$emit('create')"
        >
            Create a custom food
        </button>
    </section>
</template>
