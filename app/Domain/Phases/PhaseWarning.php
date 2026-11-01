<?php

namespace App\Domain\Phases;

/** A warning that never blocks (PH-010 to PH-012, PH-034, PH-038, PH-040, PH-042). */
final readonly class PhaseWarning
{
    /** @param  ?float  $estimatePercent  Body fat high only: the estimated body fat, percent */
    private function __construct(
        public PhaseWarningKind $kind,
        public ?float $estimatePercent = null,
    ) {}

    public static function tooManyEmphasized(): self
    {
        return new self(PhaseWarningKind::TooManyEmphasized);
    }

    public static function specialisingDuringCut(): self
    {
        return new self(PhaseWarningKind::SpecialisingDuringCut);
    }

    public static function lowVolumeSurplus(): self
    {
        return new self(PhaseWarningKind::LowVolumeSurplus);
    }

    public static function miniCutTooLong(): self
    {
        return new self(PhaseWarningKind::MiniCutTooLong);
    }

    public static function bodyFatHigh(float $estimatePercent): self
    {
        return new self(PhaseWarningKind::BodyFatHigh, $estimatePercent);
    }

    public static function runsPastEvent(): self
    {
        return new self(PhaseWarningKind::RunsPastEvent);
    }

    public static function shortMaintenanceBeforeFatLoss(): self
    {
        return new self(PhaseWarningKind::ShortMaintenanceBeforeFatLoss);
    }

    public function message(): string
    {
        return match ($this->kind) {
            PhaseWarningKind::TooManyEmphasized => 'Specialising more than 2 muscle groups spreads recovery too thin',
            PhaseWarningKind::SpecialisingDuringCut => 'Specialising during a cut works against recovery',
            PhaseWarningKind::LowVolumeSurplus => 'A surplus with a low-volume plan wastes the phase',
            PhaseWarningKind::MiniCutTooLong => 'This mini-cut is long for the gain before it. Aim for at least 2:1 gain to mini-cut',
            PhaseWarningKind::BodyFatHigh => sprintf(
                'Estimated body fat is about %d%%. Consider a Mini-cut or Fat loss', (int) round($this->estimatePercent ?? 0),
            ),
            PhaseWarningKind::RunsPastEvent => 'This phase ends after your event date',
            PhaseWarningKind::ShortMaintenanceBeforeFatLoss => 'Maintenance was under 60% of the last fat-loss phase',
        };
    }
}
