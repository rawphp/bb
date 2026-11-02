import type { PlanShowOutput } from '@/contracts/plan_show';

// How the plan screens draw a phase: its tint, and the glyphs they share.

type PhaseType = PlanShowOutput['phases'][number]['type'];

// The colour each phase type wears on the plan, as rp-clone's PhaseType.tint: Fat loss
// orange, Mini-cut red, Maintenance blue, Muscle gain green. The macro tokens are those
// four hues in light and dark (UI-011). Whole class names, so Tailwind finds each one.
// `bar` is the same hue as ProgressBar's tint.
export const phaseTints: Record<PhaseType, { fill: string; text: string; border: string; bar: 'fat' | 'protein' | 'calories' | 'carbs' }> = {
    fatLoss: { fill: 'bg-macro-fat', text: 'text-macro-fat', border: 'border-macro-fat', bar: 'fat' },
    miniCut: { fill: 'bg-macro-protein', text: 'text-macro-protein', border: 'border-macro-protein', bar: 'protein' },
    maintenance: { fill: 'bg-macro-calories', text: 'text-macro-calories', border: 'border-macro-calories', bar: 'calories' },
    muscleGain: { fill: 'bg-macro-carbs', text: 'text-macro-carbs', border: 'border-macro-carbs', bar: 'carbs' },
};

/** The filled flag (rp-clone's flag.fill) the event countdown and the event row wear. */
export const FLAG = 'M5 21V4h11l-1.5 4L16 12H7v9H5Z';
