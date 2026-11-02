<?php

namespace App\Actions\Today;

use App\Capabilities\Today\Data\DayAdjustInput;
use App\Models\User;
use App\Support\Today\AdjustWeek;
use App\Support\Today\DayPlans;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * DAY-020, NUT-021: saves a calorie move between two meals of a day, or between two days of its plan week, as the
 * Adjust sheet worked it out. A move the sheet would refuse, or one still missing a choice, is refused in the same
 * words and nothing is saved. Both days are saved together or not at all.
 */
final class AdjustDay
{
    /** @return array{dates: list<string>, week: list<string>} the days changed and every day of their plan week, Y-m-d */
    public function __invoke(User $user, DayAdjustInput $input): array
    {
        $week = AdjustWeek::load($user, $input->date);
        $outcome = $week->outcome(
            $input->mode, $input->kcal, $input->from_meal_id, $input->to_meal_id, $input->other_date, $input->direction,
            DayPlans::day(CarbonImmutable::now()),
        );
        if (! $outcome->isPlan()) {
            throw new DomainException($outcome->refusal ?? $outcome->missing ?? 'That move can\'t be made.');
        }

        return DB::transaction(function () use ($outcome, $week) {
            $dates = [];
            foreach ($outcome->changed as $changed) {
                $date = $changed['date']->format('Y-m-d');
                $row = $week->plans->row($date);
                $row->meals = DayPlans::withTargets($row->meals, $changed['meals']);
                $row->kcal = $changed['target']->kcal;
                $row->carbs_g = $changed['target']->carbsG;
                $row->save();
                $dates[] = $date;
            }

            return ['dates' => $dates, 'week' => $week->dates];
        });
    }
}
