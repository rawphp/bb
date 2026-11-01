<?php

namespace App\Domain\Defaults\TBL012TrainingDefaults;

use App\Domain\Model\PhaseType;

/** The five TBL-012 columns: maintenance is split into resensitization and balanced. */
enum Column: string
{
    case FatLoss = 'fatLoss';
    case MiniCut = 'miniCut';
    case MaintenanceResensitization = 'maintenanceResensitization';
    case MaintenanceBalanced = 'maintenanceBalanced';
    case MuscleGain = 'muscleGain';

    /** The column for a phase. The resensitization flag only matters for maintenance. */
    public static function for(PhaseType $phase, bool $isResensitization): self
    {
        return match ($phase) {
            PhaseType::FatLoss => self::FatLoss,
            PhaseType::MiniCut => self::MiniCut,
            PhaseType::MuscleGain => self::MuscleGain,
            PhaseType::Maintenance => $isResensitization ? self::MaintenanceResensitization : self::MaintenanceBalanced,
        };
    }
}
