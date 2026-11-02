<?php

namespace App\Enums;

use App\Domain\Model\PhaseType;

enum PhaseFit: string
{
    case Cut = 'cut';
    case Gain = 'gain';
    case Maintain = 'maintain';

    /** MESO-011: the templates a phase fits. Wherever a rule says Fat loss it means Mini-cut too (PLAN-009). */
    public static function for(PhaseType $phase): self
    {
        return match (true) {
            $phase->isFatLoss() => self::Cut,
            $phase === PhaseType::MuscleGain => self::Gain,
            default => self::Maintain,
        };
    }

    /** "Cut", "Gain", "Maintain". */
    public function title(): string
    {
        return ucfirst($this->value);
    }
}
