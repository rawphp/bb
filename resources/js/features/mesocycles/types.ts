import type { MesocycleShowOutput } from '@/contracts/mesocycle_show';

// Named parts of the mesocycle_show payload, for props.
export type MesocycleDetail = NonNullable<MesocycleShowOutput['saved']>;
export type MesocycleWeek = MesocycleDetail['weeks'][number];
export type MesocycleDay = MesocycleWeek['days'][number];
export type PriorityRow = MesocycleDetail['priorities'][number];
export type PriorityOption = MesocycleShowOutput['priority_options'][number];
export type Priority = PriorityOption['priority'];

// The server picks a pill's tone (CAP-018); this only maps it to the token colours.
export const TONE_CLASS = {
    good: 'bg-tone-good-bg text-tone-good',
    neutral: 'bg-tone-neutral-bg text-tone-neutral',
    warning: 'bg-tone-warning-bg text-tone-warning',
    info: 'bg-tone-info-bg text-tone-info',
} as const;
