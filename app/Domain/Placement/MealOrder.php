<?php

namespace App\Domain\Placement;

/** The order of a day's meals and how two days' meals pair (SCH-034, SCH-035). The one owner of both rules. */
final class MealOrder
{
    /** A meal's place in the day, in minutes after midnight; a meal with no time goes last. */
    public static function minute(?ClockTime $time): int
    {
        return $time === null ? ClockTime::MINUTES_PER_DAY : $time->minuteOfDay;
    }

    /** The same for a time stored as "HH:mm"; one that does not read has no time. */
    public static function minuteOfHhmm(?string $hhmm): int
    {
        return self::minute($hhmm === null ? null : ClockTime::fromHhmm($hhmm));
    }

    /**
     * The meals in time order, a meal with no time last; meals at one minute keep their order.
     *
     * @template TMeal
     *
     * @param  list<TMeal>  $meals
     * @param  callable(TMeal): ?string  $hhmm
     * @return list<TMeal>
     */
    public static function inTimeOrder(array $meals, callable $hhmm): array
    {
        $minutes = array_map(fn ($meal) => self::minuteOfHhmm($hhmm($meal)), $meals);
        $order = array_keys($meals);
        usort($order, fn (int $a, int $b) => [$minutes[$a], $a] <=> [$minutes[$b], $b]);

        return array_map(fn (int $i) => $meals[$i], $order);
    }

    /**
     * SCH-034: meal 1 of the source day goes to meal 1 of the destination day, both in time order. Extra source
     * meals are dropped and extra destination meals are left alone.
     *
     * @template TMeal
     *
     * @param  list<TMeal>  $source
     * @param  list<TMeal>  $destination
     * @param  callable(TMeal): ?string  $hhmm
     * @return list<array{source: TMeal, destination: TMeal}>
     */
    public static function pairs(array $source, array $destination, callable $hhmm): array
    {
        $source = self::inTimeOrder($source, $hhmm);
        $destination = self::inTimeOrder($destination, $hhmm);
        $pairs = [];
        for ($i = 0; $i < min(count($source), count($destination)); $i++) {
            $pairs[] = ['source' => $source[$i], 'destination' => $destination[$i]];
        }

        return $pairs;
    }
}
