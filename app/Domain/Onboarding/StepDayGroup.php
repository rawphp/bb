<?php

namespace App\Domain\Onboarding;

/** A group of weekdays that share a step preset (ONB-013). */
enum StepDayGroup: string
{
    case Weekdays = 'weekdays';
    case Weekends = 'weekends';

    /** @return list<int> ISO weekdays */
    public function weekdays(): array
    {
        return match ($this) {
            self::Weekdays => [1, 2, 3, 4, 5],
            self::Weekends => [6, 7],
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::Weekdays => 'Weekdays',
            self::Weekends => 'Weekends',
        };
    }
}
