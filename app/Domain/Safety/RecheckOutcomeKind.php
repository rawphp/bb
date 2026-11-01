<?php

namespace App\Domain\Safety;

/** SAFE-017: what "Re-check eligibility" does. */
enum RecheckOutcomeKind: string
{
    /** A training-only user now passes: run Screening::NUTRITION_SETUP_STEPS. */
    case RunNutritionSetup = 'runNutritionSetup';
    /** A nutrition user now fails: training-only at once, with the reasons shown. */
    case SwitchToTrainingOnly = 'switchToTrainingOnly';
    /** SAFE-001: the current phase is Fat loss or Mini-cut and that is now blocked; pick Maintenance or Muscle gain. */
    case LeaveFatLoss = 'leaveFatLoss';
    /** The mode stays; store the new result. */
    case Keep = 'keep';
}
