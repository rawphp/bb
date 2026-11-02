// How each status tone looks (UI-004). The server picks the tone (CAP-018); every
// pill, notice and banner takes its colours from here. Whole class names, so
// Tailwind finds every token utility.
export const toneClasses = {
    good: 'text-tone-good bg-tone-good-bg',
    neutral: 'text-tone-neutral bg-tone-neutral-bg',
    warning: 'text-tone-warning bg-tone-warning-bg',
    info: 'text-tone-info bg-tone-info-bg',
} as const;
