<script setup lang="ts">
import CheckInBox from '@/design/CheckInBox.vue';
import { checkInLook, type CheckInState } from '@/design/checkInLook';

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
        <CheckInBox :shape="checkInLook[state].shape" />
    </button>
</template>
