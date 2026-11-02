<?php

namespace App\Domain\Progress;

use App\Capabilities\Support\Tone;

/** DAY-010: the status of a day's totals against its target. */
final readonly class DayStatus
{
    public const ON_TRACK_TEXT = 'on track';

    public function __construct(
        public string $text,
        public Tone $tone,
    ) {}

    /**
     * "on track" when calories and protein are each within 10% of target; otherwise how far calories are
     * under or over ("315 under"), or protein when only protein is off ("20 g protein under").
     */
    public static function make(DayTotals $consumed, DayTotals $target): self
    {
        if (! Adherence::within($consumed->kcal, $target->kcal)) {
            return new self(self::describe($consumed->kcal - $target->kcal, ''), Tone::Warning);
        }
        if (! Adherence::within($consumed->proteinG, $target->proteinG)) {
            return new self(self::describe($consumed->proteinG - $target->proteinG, ' g protein'), Tone::Warning);
        }

        return new self(self::ON_TRACK_TEXT, Tone::Good);
    }

    private static function describe(float $difference, string $unit): string
    {
        $amount = (int) round(abs($difference));

        return $amount.$unit.($difference < 0 ? ' under' : ' over');
    }
}
