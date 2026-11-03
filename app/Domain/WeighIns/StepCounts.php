<?php

namespace App\Domain\WeighIns;

use App\Enums\MeasurementSource;
use DateTimeImmutable;

/** A day's step count, typed in or posted by the Shortcuts bridge (WT-020). */
final class StepCounts
{
    /**
     * WT-020: whether a new count for a day replaces the one it has. A typed count always does; the bridge never
     * overwrites a typed count, and its newer count replaces its older one.
     */
    public static function replaces(?MeasurementSource $current, MeasurementSource $incoming): bool
    {
        return $current === null || $incoming === MeasurementSource::Manual || $current === MeasurementSource::Shortcut;
    }

    /** WT-020: a new entry starts on yesterday, since today's count is not final until the day ends. */
    public static function defaultDate(DateTimeImmutable $today): DateTimeImmutable
    {
        return $today->setTime(0, 0)->modify('-1 day');
    }

    public static function sourceLabel(MeasurementSource $source): string
    {
        return match ($source) {
            MeasurementSource::Manual => 'Typed',
            MeasurementSource::Shortcut => 'Shortcut',
        };
    }
}
