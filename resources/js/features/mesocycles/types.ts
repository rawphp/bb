import type { MesocycleShowOutput } from '@/contracts/mesocycle_show';

// Named parts of the mesocycle_show payload, for props.
export type MesocycleDetail = NonNullable<MesocycleShowOutput['saved']>;
export type MesocycleWeek = MesocycleDetail['weeks'][number];
export type MesocycleDay = MesocycleWeek['days'][number];
export type PriorityRow = MesocycleDetail['priorities'][number];
export type PriorityOption = MesocycleShowOutput['priority_options'][number];
export type Priority = PriorityOption['priority'];


// A server-derived number dimmed while a write waits to sync (OFF-008), as PendingMark's
// `derived` dims its value. Used where a number sits under a PendingMark already shown
// nearby (a week's set counts under its calorie line), so each column has one mark, not
// one per number.
export const PENDING_DIM = 'opacity-60';
