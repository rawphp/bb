// The keypad each kind of number field opens (PWA-014). Bind with v-bind:
// <input type="text" v-bind="KEYPAD.weight">. Decimal for weights and grams,
// whole numbers for reps and steps.
export const KEYPAD = {
    weight: { inputmode: 'decimal' },
    grams: { inputmode: 'decimal' },
    reps: { inputmode: 'numeric', pattern: '[0-9]*' },
    steps: { inputmode: 'numeric', pattern: '[0-9]*' },
} as const;
