// The server picks a status's tone (CAP-018); this only says how each tone looks.
export const TONE_CLASS = {
    good: 'bg-tone-good-bg text-tone-good',
    neutral: 'bg-tone-neutral-bg text-tone-neutral',
    warning: 'bg-tone-warning-bg text-tone-warning',
    info: 'bg-tone-info-bg text-tone-info',
} as const;
