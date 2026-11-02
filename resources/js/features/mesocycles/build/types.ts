import type { MesocycleBuildInput } from '@/contracts/mesocycle_build';
import type { MesocycleShowOutput } from '@/contracts/mesocycle_show';

// Named parts of the build flow's payload, for props.
export type Build = NonNullable<MesocycleShowOutput['build']>;
export type BuildDay = Build['days'][number];
export type BuildSlot = BuildDay['slots'][number];
export type BuildMuscle = Build['exercise_options'][number];
export type LabelStyle = Build['label_style'];
export type BuildRoute = Build['route'];

/** The days a mesocycle_build write takes: every day with its slots (MESO-013, MESO-014). */
export type BuildDaysInput = NonNullable<MesocycleBuildInput['days']>;

/** The steps of the flow. `template` is only the template route's first. */
export type BuildStep = 'template' | 'builder' | 'scratch' | 'slots' | 'details';

export const STEPS: BuildStep[] = ['template', 'builder', 'scratch', 'slots', 'details'];
