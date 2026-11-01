<?php

namespace App\Domain\Model;

/** The four phase types (TBL-010). */
enum PhaseType: string
{
    case FatLoss = 'fatLoss';
    case MiniCut = 'miniCut';
    case Maintenance = 'maintenance';
    case MuscleGain = 'muscleGain';

    /** PLAN-009: wherever a rule says "Fat loss" it also applies to Mini-cut. */
    public function isFatLoss(): bool
    {
        return $this === self::FatLoss || $this === self::MiniCut;
    }
}
