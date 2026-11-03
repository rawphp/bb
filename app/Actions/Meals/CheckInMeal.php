<?php

namespace App\Actions\Meals;

use App\Capabilities\Meal\Data\MealCheckInInput;
use App\Capabilities\Reads\Today\TodayPayload;
use App\Domain\Model\MealTarget;
use App\Models\DayTargetMeal;
use App\Models\MealCheckIn;
use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\OccurredAt;
use App\Support\Today\TodayMealItem;
use App\Support\Today\TodaySnapshot;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * MEAL-040 to MEAL-042, MEAL-045: check a meal in, replacing the check-in already there (one per meal). With no amounts
 * it records what the planned foods and recipe servings add up to, or the meal's targets when nothing is planned
 * ("check in as given"); a meal showing the "Adjust foods" badge (DAY-003) refuses that one-tap check-in. All four
 * amounts record what the user entered, marked manual, on any meal. With no time, the time is when the user acted
 * (OFF-007), which the outbox may send days later, in the app's timezone.
 */
final class CheckInMeal
{
    public function __invoke(User $user, MealCheckInInput $input): DayTargetMeal
    {
        $meal = DayTargetMeal::query()->liveOf($user)->with('dayTarget')->findOrFail($input->meal_id);
        // The input's rules take all four amounts or none.
        $eaten = $input->kcal === null ? $this->planned($user, $meal) : new MealTarget(
            kcal: (float) $input->kcal, proteinG: (float) $input->protein_g, fatG: (float) $input->fat_g, carbsG: (float) $input->carbs_g,
        );

        MealCheckIn::query()->updateOrCreate(['meal_id' => $meal->getKey()], [
            'user_id' => $user->id,
            'time' => $input->time ?? OccurredAt::get()->setTimezone(config('app.timezone'))->format('H:i'),
            'kcal' => $eaten->kcal,
            'protein_g' => $eaten->proteinG,
            'fat_g' => $eaten->fatG,
            'carbs_g' => $eaten->carbsG,
            'is_manual' => $input->kcal !== null,
        ]);

        return $meal;
    }

    /** MEAL-040, MEAL-042, MEAL-045: the one-tap check-in, read from the day as Today shows it. */
    private function planned(User $user, DayTargetMeal $meal): MealTarget
    {
        $day = TrainSnapshot::day($meal->dayTarget->date);
        $item = collect(TodaySnapshot::load($user, $day->format('Y-m-d'))->meals($day))
            ->first(fn (TodayMealItem $item) => strtolower($item->id) === strtolower((string) $meal->getKey()))
            ?? throw (new ModelNotFoundException)->setModel(DayTargetMeal::class, [$meal->getKey()]);

        if ($item->showsAdjustBadge()) {
            throw new DomainException(TodayPayload::ADJUST_FIRST);
        }

        return $item->plannedCheckIn();
    }
}
