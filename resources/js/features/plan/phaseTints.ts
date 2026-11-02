import type { PlanShowOutput } from '@/contracts/plan_show';

type PhaseType = PlanShowOutput['phases'][number]['type'];

// The colour each phase type wears on the plan, as rp-clone's PhaseType.tint: Fat loss
// orange, Mini-cut red, Maintenance blue, Muscle gain green. The macro tokens are those
// four hues in light and dark (UI-011). Whole class names, so Tailwind finds each one.
export const phaseTints: Record<PhaseType, { fill: string; text: string; border: string }> = {
    fatLoss: { fill: 'bg-macro-fat', text: 'text-macro-fat', border: 'border-macro-fat' },
    miniCut: { fill: 'bg-macro-protein', text: 'text-macro-protein', border: 'border-macro-protein' },
    maintenance: { fill: 'bg-macro-calories', text: 'text-macro-calories', border: 'border-macro-calories' },
    muscleGain: { fill: 'bg-macro-carbs', text: 'text-macro-carbs', border: 'border-macro-carbs' },
};

/** The filled flag (rp-clone's flag.fill) the event countdown and the event row wear. */
export const FLAG = 'M5 21V4h11l-1.5 4L16 12H7v9H5Z';
