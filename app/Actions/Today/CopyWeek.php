<?php

namespace App\Actions\Today;

use App\Domain\Copy\CopyOptions;
use App\Models\DayTarget;
use App\Models\DayTargetMeal;
use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Today\DayPlans;
use App\Support\Today\TodaySnapshot;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * SCH-035: copies every planned food of a plan week to the next one, with the default MEAL-060 options. Each day's
 * meals pair, as in Copy day, with those of the same weekday a week later; a day without a target on either side has
 * nothing to pair. The week is the 7 days the week strip shows around `$weekStart` ({@see TodaySnapshot::weekStart()}).
 */
final class CopyWeek
{
    public function __construct(private readonly CopyMeals $copy) {}

    /**
     * @return list<DayTargetMeal> the destination meals written
     *
     * @throws ModelNotFoundException when the user has no target in the week
     * @throws DomainException when the next week has no day to copy to
     */
    public function __invoke(User $user, string $weekStart): array
    {
        $first = TodaySnapshot::loadWeek($user, $weekStart)->weekStart(TrainSnapshot::day($weekStart));
        $dates = array_map(fn (int $offset) => $first->modify("+{$offset} days")->format('Y-m-d'), range(0, 13));
        $days = DayTarget::query()->where('user_id', $user->id)->whereIn('date', $dates)->get()
            ->keyBy(fn (DayTarget $day) => DayPlans::day($day->date)->format('Y-m-d'));
        if (array_filter(array_slice($dates, 0, 7), fn (string $date) => $days->has($date)) === []) {
            throw (new ModelNotFoundException)->setModel(DayTarget::class, [$dates[0]]);
        }

        $pairs = [];
        for ($offset = 0; $offset < 7; $offset++) {
            $from = $days->get($dates[$offset]);
            $to = $days->get($dates[$offset + 7]);
            if ($from !== null && $to !== null) {
                array_push($pairs, ...CopyDay::pairs($from, $to));
            }
        }
        if ($pairs === []) {
            throw new DomainException('There is no next week in the plan to copy to.');
        }

        return ($this->copy)($user, $pairs, new CopyOptions);
    }
}
