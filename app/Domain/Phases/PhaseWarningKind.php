<?php

namespace App\Domain\Phases;

/** The warnings that never block (PH-010 to PH-012, PH-034, PH-038, PH-040, PH-042). */
enum PhaseWarningKind: string
{
    case TooManyEmphasized = 'tooManyEmphasized';
    case SpecialisingDuringCut = 'specialisingDuringCut';
    case LowVolumeSurplus = 'lowVolumeSurplus';
    case MiniCutTooLong = 'miniCutTooLong';
    case BodyFatHigh = 'bodyFatHigh';
    case RunsPastEvent = 'runsPastEvent';
    case ShortMaintenanceBeforeFatLoss = 'shortMaintenanceBeforeFatLoss';
}
