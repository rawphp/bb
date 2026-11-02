import type { MesocycleShowOutput } from '@/contracts/mesocycle_show';

// Named parts of the mesocycle_show payload, for props.
export type MesocycleDetail = NonNullable<MesocycleShowOutput['saved']>;
export type MesocycleWeek = MesocycleDetail['weeks'][number];
export type MesocycleDay = MesocycleWeek['days'][number];
export type PriorityRow = MesocycleDetail['priorities'][number];
export type PriorityOption = MesocycleShowOutput['priority_options'][number];
export type Priority = PriorityOption['priority'];

