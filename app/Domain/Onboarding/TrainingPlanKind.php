<?php

namespace App\Domain\Onboarding;

/** ONB-012: what the user picked for the first mesocycle. */
enum TrainingPlanKind: string
{
    case Template = 'template';
    /** The Meso Builder (MESO-020). It records "build later" until its screen exists. */
    case MesoBuilder = 'meso_builder';
    /** "I'll build it later". */
    case Later = 'later';
}
