<?php

namespace App\Actions\Today;

use App\Domain\Copy\CopyOptions;
use App\Domain\Placement\MealOrder;
use App\Models\DayTarget;
use App\Models\DayTargetMeal;
use App\Models\User;
use App\Support\Today\DayPlans;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * SCH-034: copies every planned food of a day to each of the other days, its meals paired with theirs by their order in
 * the day ({@see MealOrder::pairs()}), with the MEAL-060 options. Only days of the user's plan with a target take the
 * copy: the source day itself, a day before the plan and a day without a target are skipped.
 */
final class CopyDay
{
    public function __construct(private readonly CopyMeals $copy) {}

    /**
     * @param  list<string>  $targetDates  Y-m-d
     * @return list<DayTargetMeal> the destination meals written
     *
     * @throws ModelNotFoundException when the user has no target on the source day
     */
    public function __invoke(User $user, string $sourceDate, array $targetDates, CopyOptions $options): array
    {
        $days = DayTarget::query()->where('user_id', $user->id)
            ->whereIn('date', array_values(array_unique([$sourceDate, ...$targetDates])))
            ->get()
            ->keyBy(fn (DayTarget $day) => DayPlans::day($day->date)->format('Y-m-d'));
        $source = $days->get($sourceDate) ?? throw (new ModelNotFoundException)->setModel(DayTarget::class, [$sourceDate]);

        $dates = CopyMeals::inPlan($user, array_values(array_unique($targetDates)));
        sort($dates);
        $pairs = [];
        foreach ($dates as $date) {
            $destination = $days->get($date);
            if ($destination === null || $date === $sourceDate) {
                continue;
            }
            array_push($pairs, ...self::pairs($source, $destination));
        }

        return ($this->copy)($user, $pairs, $options);
    }

    /**
     * SCH-034, SCH-035: a day's meals paired with another day's by position in time order.
     *
     * @return list<array{0: string, 1: string}> source meal id, destination meal id
     */
    public static function pairs(DayTarget $source, DayTarget $destination): array
    {
        return array_map(
            fn (array $pair) => [(string) $pair['source']['id'], (string) $pair['destination']['id']],
            MealOrder::pairs($source->meals, $destination->meals, fn (array $meal) => isset($meal['time']) ? (string) $meal['time'] : null),
        );
    }
}
