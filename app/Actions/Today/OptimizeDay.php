<?php

namespace App\Actions\Today;

use App\Capabilities\Reads\Data\DayOptimizeShowOutput;
use App\Capabilities\Reads\Data\Today\OptimizeRow;
use App\Capabilities\Today\Data\DayOptimizeInput;
use App\Domain\Retarget\MealRetarget;
use App\Domain\Retarget\Retargeting;
use App\Domain\Retarget\RetargetMeal;
use App\Domain\Retarget\RetargetRefused;
use App\Models\User;
use Closure;
use DomainException;

/**
 * SCH-036: Optimize schedule places the day's unchecked meals again by SCH-040 to SCH-046 around its session, and
 * splits them from the same total. The preview and the save run the one rule on the same day, so the save is what
 * the preview showed.
 */
final class OptimizeDay
{
    public const ALREADY_BEST = 'Your meals are already in the best places.';

    /** The preview's id for a meal the result adds; the save gives it a real one. */
    public const PREVIEW_ID = 'shake';

    /** Each meal's time before and after; saves nothing. A day that cannot be optimized says why. */
    public static function preview(User $user, string $date): DayOptimizeShowOutput
    {
        try {
            $day = RetargetableDay::load($user, $date);
            $result = self::result($day, fn (): string => self::PREVIEW_ID);
        } catch (DomainException $refused) {
            $message = $refused instanceof RetargetRefused ? RetargetableDay::refused($refused)->getMessage() : $refused->getMessage();

            return new DayOptimizeShowOutput($date, isset($day) ? self::rows($day->day->meals, $day->day->meals) : [], $message, false);
        }
        if ($result === null) {
            return new DayOptimizeShowOutput($date, self::rows($day->day->meals, $day->day->meals), self::ALREADY_BEST, false);
        }

        return new DayOptimizeShowOutput($date, self::rows($day->day->meals, $result->days[0]->meals), null, true);
    }

    /** @return array{toast: string, dates: list<string>, week: list<string>} the toast, the day (Y-m-d) and its plan week */
    public function __invoke(User $user, DayOptimizeInput $input): array
    {
        $day = RetargetableDay::load($user, $input->date);
        try {
            $result = self::result($day, RetargetableDay::newId(...)) ?? throw new DomainException(self::ALREADY_BEST);
        } catch (RetargetRefused $refused) {
            throw RetargetableDay::refused($refused);
        }
        $day->save($result->days[0]);

        return ['toast' => $result->message, 'dates' => [$day->date], 'week' => $day->weekDates()];
    }

    /**
     * @param  Closure(): string  $newId
     *
     * @throws RetargetRefused
     */
    private static function result(RetargetableDay $day, Closure $newId): ?MealRetarget
    {
        return Retargeting::optimize($day->day, $day->context, $newId);
    }

    /**
     * The meals after, in time order, then any the result drops.
     *
     * @param  list<RetargetMeal>  $before
     * @param  list<RetargetMeal>  $after
     * @return list<OptimizeRow>
     */
    private static function rows(array $before, array $after): array
    {
        $was = [];
        foreach ($before as $meal) {
            $was[$meal->id()] = $meal;
        }
        $rows = [];
        foreach ($after as $meal) {
            $old = $was[$meal->id()] ?? null;
            unset($was[$meal->id()]);
            $rows[] = self::row($meal, $old?->time->shortText(), $meal->time->shortText());
        }
        foreach ($was as $meal) {
            $rows[] = self::row($meal, $meal->time->shortText(), null);
        }

        return $rows;
    }

    private static function row(RetargetMeal $meal, ?string $before, ?string $after): OptimizeRow
    {
        return new OptimizeRow($meal->id(), $meal->meal->name, $before, $after, $before !== $after);
    }
}
