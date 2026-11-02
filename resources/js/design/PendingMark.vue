<script setup lang="ts">
// A value waiting in the outbox, with a small "Waiting to sync" mark beside it.
// The user's own input shows as typed (OFF-009); a server-derived value is dimmed (OFF-008).
defineOptions({ inheritAttrs: false });

withDefaults(
    defineProps<{
        /** The value is server-derived (a total, remaining macros), so it is dimmed until replay. */
        derived?: boolean;
    }>(),
    { derived: false },
);
</script>

<template>
    <span class="inline-flex flex-wrap items-baseline gap-x-1.5">
        <span v-if="$slots.default" data-value :class="derived ? 'opacity-60' : ''"><slot /></span>
        <span v-bind="$attrs" data-mark class="inline-flex items-center gap-1 text-xs text-secondary-text">
            <svg aria-hidden="true" viewBox="0 0 24 24" class="size-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="9" />
                <path d="M12 7v5l3 2" />
            </svg>
            Waiting to sync
        </span>
    </span>
</template>
