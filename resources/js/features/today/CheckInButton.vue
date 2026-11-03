<script setup lang="ts">
// MEAL-040, MEAL-047: a meal's check-in box, for the meal screen; Today's cards draw the
// same box through ItemCard. Its icon shows the check-in state the server sends, with a
// shape as well as a colour (UI-030); a tap checks the meal in or removes the check-in.
// Ported from rp-clone's CheckInButton.
type State = 'not_checked_in' | 'on_track' | 'under' | 'over';

withDefaults(defineProps<{ state: State; label: string; mealName: string; disabled?: boolean }>(), { disabled: false });

const emit = defineEmits<{ toggle: [] }>();

const colours: Record<State, string> = {
    not_checked_in: 'text-secondary-text',
    on_track: 'text-tone-good',
    under: 'text-tone-warning',
    over: 'text-tone-warning',
};

const marks: Partial<Record<State, string>> = {
    on_track: 'M8 12.5l2.75 2.75L16 9.5',
    under: 'M12 7.5v9M8 12.5l4 4 4-4',
    over: 'M12 16.5v-9M8 11.5l4-4 4 4',
};
</script>

<template>
    <button
        type="button"
        role="checkbox"
        :aria-checked="state !== 'not_checked_in'"
        :aria-label="`${mealName} eaten`"
        :aria-description="label"
        :disabled="disabled"
        class="flex min-h-tap min-w-tap items-center justify-center disabled:opacity-50"
        :class="colours[state]"
        @click="emit('toggle')"
    >
        <svg viewBox="0 0 24 24" class="size-7" aria-hidden="true" :data-shape="state">
            <rect x="3.5" y="3.5" width="17" height="17" rx="3.5" stroke="currentColor" stroke-width="1.75" :fill="state === 'not_checked_in' ? 'none' : 'currentColor'" />
            <path v-if="marks[state]" :d="marks[state]" class="stroke-on-accent" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
    </button>
</template>
