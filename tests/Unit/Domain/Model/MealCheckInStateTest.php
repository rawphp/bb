<?php

use App\Domain\Model\MealCheckInState;
use App\Domain\Model\MealFitRules;
use App\Domain\Model\MealTarget;

/**
 * MEAL-043's rule as rp-clone's MealFit.targetsMet states it: protein, fat and carbs each within 5 g.
 * D_MEALFIT ships the real implementation of MealFitRules.
 */
function within5g(): MealFitRules
{
    return new class implements MealFitRules
    {
        public function targetsMet(MealTarget $total, MealTarget $target): bool
        {
            foreach ([[$total->proteinG, $target->proteinG], [$total->fatG, $target->fatG], [$total->carbsG, $target->carbsG]] as [$have, $goal]) {
                if (abs($have - $goal) > 5.0 + 1e-9) {
                    return false;
                }
            }

            return true;
        }
    };
}

function checkIn(float $kcal, float $protein, float $fat, float $carbs): MealCheckInState
{
    return MealCheckInState::of(
        new MealTarget(kcal: $kcal, proteinG: $protein, fatG: $fat, carbsG: $carbs),
        new MealTarget(kcal: 780, proteinG: 51, fatG: 21, carbsG: 96),
        within5g(),
    );
}

it('MEAL-047 a meal with no check-in is not checked in', function () {
    $state = MealCheckInState::of(null, new MealTarget(kcal: 780, proteinG: 51, fatG: 21, carbsG: 96), within5g());

    expect($state)->toBe(MealCheckInState::NotCheckedIn)
        ->and($state->isCheckedIn())->toBeFalse();
});

it('MEAL-043 every macro within 5 g is on track', function (float $kcal, float $protein, float $fat, float $carbs) {
    expect(checkIn($kcal, $protein, $fat, $carbs))->toBe(MealCheckInState::OnTrack);
})->with([
    'exact' => [780, 51, 21, 96],
    '5 g off on each macro is still on track' => [810, 56, 16, 101],
    'calories do not decide on track' => [700, 46, 26, 91],
]);

it('MEAL-043 one macro more than 5 g off is not on track', function (float $kcal, float $protein, float $fat, float $carbs) {
    expect(checkIn($kcal, $protein, $fat, $carbs))->not->toBe(MealCheckInState::OnTrack);
})->with([
    'carbs' => [780, 51, 21, 101.5],
    'protein' => [780, 45.5, 21, 96],
    'fat' => [780, 51, 26.5, 96],
]);

it('MEAL-047 off target, calories decide under or over', function (float $kcal, float $protein, float $fat, float $carbs, MealCheckInState $expected) {
    expect(checkIn($kcal, $protein, $fat, $carbs))->toBe($expected);
})->with([
    [400, 30, 10, 50, MealCheckInState::Under],
    [1100, 60, 30, 150, MealCheckInState::Over],
    'protein over and carbs under, calories under' => [700, 70, 21, 60, MealCheckInState::Under],
    'protein under and carbs over, calories over' => [850, 40, 21, 130, MealCheckInState::Over],
]);

it('MEAL-047 each state has its own label', function () {
    $states = [MealCheckInState::NotCheckedIn, MealCheckInState::OnTrack, MealCheckInState::Under, MealCheckInState::Over];

    expect(array_map(fn (MealCheckInState $state) => $state->label(), $states))
        ->toBe(['Not checked in', 'Checked in, on track', 'Checked in, under target', 'Checked in, over target'])
        ->and(MealCheckInState::OnTrack->isCheckedIn())->toBeTrue()
        ->and(MealCheckInState::Under->isCheckedIn())->toBeTrue()
        ->and(MealCheckInState::Over->isCheckedIn())->toBeTrue();
});
