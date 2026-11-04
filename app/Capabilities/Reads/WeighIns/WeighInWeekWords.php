<?php

namespace App\Capabilities\Reads\WeighIns;

use App\Capabilities\Support\Status;
use App\Domain\Model\UnitConversion;
use App\Domain\WeighIns\BodyMeasures;
use DateTimeImmutable;

/** WT-008: the words over each week of the Weigh-ins list, in the user's unit (CAP-015). */
final class WeighInWeekWords
{
    /** "This week" for the week holding today; else "24 – 30 Oct", "26 Sep – 2 Oct", with the year outside this one. */
    public static function title(DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $today): string
    {
        $day = $today->format('Y-m-d');
        if ($start->format('Y-m-d') <= $day && $day <= $end->format('Y-m-d')) {
            return 'This week';
        }

        $year = $end->format('Y') === $today->format('Y') && $start->format('Y') === $end->format('Y') ? '' : ' Y';
        $last = $end->format('j M'.$year);
        if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
            return $last;
        }
        $first = match (true) {
            $start->format('Y') !== $end->format('Y') => $start->format('j M Y'),
            $start->format('m') !== $end->format('m') => $start->format('j M'),
            default => $start->format('j'),
        };

        return "{$first} – {$last}";
    }

    /** "Trend 81.8 kg"; empty without a trend. */
    public static function trend(?float $kg, string $unit): string
    {
        return $kg === null ? '' : 'Trend '.BodyMeasures::weightLabel($kg, $unit);
    }

    /** "+0.3 kg on last week", "−0.2 kg on last week" or "Same as last week"; null with no week before. */
    public static function change(?float $kg, string $unit): ?Status
    {
        if ($kg === null) {
            return null;
        }
        $tenths = (int) round(($unit === 'lb' ? UnitConversion::kgToLb($kg) : $kg) * 10);
        if ($tenths === 0) {
            return new Status('Same as last week', 'info');
        }

        return new Status(($tenths > 0 ? '+' : '−').number_format(abs($tenths) / 10, 1, '.', '')." {$unit} on last week", 'info');
    }
}
