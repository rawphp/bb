<?php

namespace App\Support\Plan;

use App\Domain\Model\DayType;
use App\Models\DayTarget;
use App\Models\Phase;
use App\Models\User;
use DateTimeImmutable;

/**
 * What a phase's day targets and check-ins say, for its transition (PH-030, PH-044, PH-046): the share of planned
 * meals checked in, and the final week's average calories, fat and training-day carbs. Null where there are no
 * targets to read (training-only mode, or none saved).
 */
final readonly class PhaseIntake
{
    public function __construct(
        /** 0 to 1 */
        public ?float $adherence = null,
        public ?float $finalWeekKcal = null,
        public ?float $finalWeekFatG = null,
        public ?float $finalWeekTrainingCarbsG = null,
    ) {}

    /** The phase's targets from its start to the day before `$end`, the first day of what comes next. */
    public static function load(User $user, Phase $phase, DateTimeImmutable $end): self
    {
        $targets = DayTarget::query()->where('user_id', $user->id)
            ->where('date', '>=', PlanSnapshot::day($phase->start_date)->format('Y-m-d'))
            ->where('date', '<', $end->format('Y-m-d'))
            ->with('mealIndex.checkIn')
            ->get();
        if ($targets->isEmpty()) {
            return new self;
        }
        $meals = $targets->flatMap(fn (DayTarget $target) => $target->mealIndex);
        $checked = $meals->filter(fn ($meal) => $meal->checkIn !== null)->count();

        $from = $end->modify('-7 days');
        $final = $targets->filter(fn (DayTarget $target) => PlanSnapshot::day($target->date) >= $from);
        $training = $final->filter(fn (DayTarget $target) => ! in_array($target->day_type, [DayType::Rest, DayType::Deload], true));

        return new self(
            adherence: $meals->isEmpty() ? null : $checked / $meals->count(),
            finalWeekKcal: $final->isEmpty() ? null : (float) $final->avg('kcal'),
            finalWeekFatG: $final->isEmpty() ? null : (float) $final->avg('fat_g'),
            finalWeekTrainingCarbsG: $training->isEmpty() ? null : (float) $training->avg('carbs_g'),
        );
    }
}
