<script setup lang="ts">
import { checkInLook, checkMarks, type CheckInState } from '@/design/checkInLook';

// MEAL-040, MEAL-047: a meal's check-in box, for the meal screen; Today's cards draw the
// same box through ItemCard, from the same look (design/checkInLook). Its icon shows the
// check-in state the server sends, with a shape as well as a colour (UI-030); a tap checks
// the meal in or removes the check-in. Ported from rp-clone's CheckInButton.
withDefaults(defineProps<{ state: CheckInState; label: string; mealName: string; disabled?: boolean }>(), { disabled: false });

const emit = defineEmits<{ toggle: [] }>();
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
        :class="checkInLook[state].color"
        @click="emit('toggle')"
    >
        <svg viewBox="0 0 24 24" class="size-7" aria-hidden="true" :data-shape="state">
            <rect x="3.5" y="3.5" width="17" height="17" rx="3.5" stroke="currentColor" stroke-width="1.75" :fill="state === 'not_checked_in' ? 'none' : 'currentColor'" />
            <path
                v-if="checkInLook[state].shape !== 'empty'"
                :d="checkMarks[checkInLook[state].shape as keyof typeof checkMarks]"
                class="stroke-on-accent"
                fill="none"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </svg>
    </button>
</template>
