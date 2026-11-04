<?php

use App\Capabilities\Reads\Review\ReviewWeekPayload;
use App\Domain\Model\MealCheckInState;
use App\Domain\Review\ReviewMealItem;
use App\Domain\Review\ReviewMealsSummary;

// REV-006: the meals step lays the week out one row per day, with a mark for each meal.

function rvMealItem(string $id, string $day, string $name, bool $checked): ReviewMealItem
{
    return new ReviewMealItem($id, new DateTimeImmutable($day), $name, $checked ? MealCheckInState::OnTrack : MealCheckInState::NotCheckedIn);
}

it('REV-006 groups the week\'s meals into one row per day, in order, each meal marked checked in or not', function () {
    $days = ReviewWeekPayload::mealDays(new ReviewMealsSummary([
        rvMealItem('m1', '2026-10-24', 'Breakfast', true),
        rvMealItem('m2', '2026-10-24', 'Dinner', false),
        rvMealItem('m3', '2026-10-25', 'Breakfast', false),
    ]));

    expect(array_map(fn ($day) => $day->toArray(), $days))->toBe([
        [
            'date' => '2026-10-24', 'weekday' => 'Sat', 'day_number' => '24',
            'meals' => [
                ['meal_id' => 'm1', 'name' => 'Breakfast', 'is_checked' => true, 'check_in' => 'on_track', 'label' => 'Breakfast, Sat, 24 Oct: checked in, on track'],
                ['meal_id' => 'm2', 'name' => 'Dinner', 'is_checked' => false, 'check_in' => 'not_checked_in', 'label' => 'Check in Dinner, Sat, 24 Oct'],
            ],
        ],
        [
            'date' => '2026-10-25', 'weekday' => 'Sun', 'day_number' => '25',
            'meals' => [
                ['meal_id' => 'm3', 'name' => 'Breakfast', 'is_checked' => false, 'check_in' => 'not_checked_in', 'label' => 'Check in Breakfast, Sun, 25 Oct'],
            ],
        ],
    ]);
});

it('REV-006 a week with no planned meals has no rows', function () {
    expect(ReviewWeekPayload::mealDays(new ReviewMealsSummary([])))->toBe([]);
});
