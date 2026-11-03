<?php

// PHP-only: Swift took a Calendar argument. Here days are read in the zone of the plan start, and weigh-ins and
// "today" arrive in UTC, as they come back from the database.

use App\Domain\Macros\MoveRefusalReason;
use App\Domain\Model\PlanWeek;
use App\Domain\Model\WeighIn;
use App\Domain\Plan\PlanWeekGrid;
use App\Domain\Review\NextWeekCalories;
use App\Domain\Review\ReviewNextWeek;
use App\Domain\Review\ReviewTiming;
use App\Domain\Review\ReviewWeighIns;
use App\Domain\Review\StoredPlanWeek;
use App\Domain\WeekBuilder\CalorieMove;
use App\Domain\WeekBuilder\WeekBuildRefusal;
use App\Domain\WeekBuilder\WeekBuildRefusalReason;
use Tests\Unit\Domain\Plan\PlanFixtures;
use Tests\Unit\Domain\Review\NextWeekFixtures as F;
use Tests\Unit\Domain\WeekBuilder\WeekBuilderFixtures as W;

const REVIEW_TZ_BRISBANE = 'Australia/Brisbane';

function reviewTzBrisbane(int $day, int $hour = 0): DateTimeImmutable
{
    return PlanFixtures::date(2026, 1, $day, $hour, 0, REVIEW_TZ_BRISBANE);
}

function reviewTzUtc(string $text): DateTimeImmutable
{
    return new DateTimeImmutable($text, new DateTimeZone('UTC'));
}

it('REV-001 a UTC weigh-in counts toward the week on its Brisbane day', function () {
    // The plan starts Monday 5 January in Brisbane (UTC+10); the week that ended is 5 to 11 January.
    $grid = new PlanWeekGrid(reviewTzBrisbane(5), PlanFixtures::MONDAY);
    $today = reviewTzBrisbane(12, 9);
    $inWeek = reviewTzBrisbane(6, 7);

    // 21:00 UTC on 11 January is 07:00 on Monday 12 January in Brisbane: the new week, so it does not count.
    $nextWeek = ReviewTiming::status($today, $grid, [], [$inWeek, reviewTzUtc('2026-01-11 21:00')]);
    // 20:00 UTC on 4 January is 06:00 on Monday 5 January in Brisbane: the first day of the week, so it counts.
    $firstDay = ReviewTiming::status($today, $grid, [], [$inWeek, reviewTzUtc('2026-01-04 20:00')]);

    expect($nextWeek->isAvailable())->toBeFalse()
        ->and($firstDay->isAvailable())->toBeTrue();
});

it('REV-001 MESO-044 a stored week row dated at UTC midnight matches its Brisbane week', function () {
    // A date column comes back as midnight UTC: 10:00 on the same day in Brisbane.
    $grid = new PlanWeekGrid(reviewTzBrisbane(5), PlanFixtures::MONDAY);
    $weighIns = [reviewTzBrisbane(6, 7), reviewTzBrisbane(9, 7)];
    $flagged = [
        new StoredPlanWeek(start: reviewTzUtc('2026-01-05 00:00'), isDeload: true),
        new StoredPlanWeek(start: reviewTzUtc('2026-01-12 00:00'), isDietBreak: true),
    ];
    $reviewed = [new StoredPlanWeek(start: reviewTzUtc('2026-01-05 00:00'), reviewedAt: reviewTzUtc('2026-01-11 23:00'))];

    $period = ReviewTiming::status(reviewTzBrisbane(12, 9), $grid, $flagged, $weighIns)->period();

    expect($period->reviewed->isDeload)->toBeTrue()
        ->and($period->next->isDietBreak)->toBeTrue()
        ->and(ReviewTiming::status(reviewTzBrisbane(12, 9), $grid, $reviewed, $weighIns)->isDone())->toBeTrue();
});

it('REV-001 MESO-044 a stored week row dated at UTC midnight matches its New York week', function () {
    // West of UTC, midnight UTC is the evening before in New York: the row's date, not its instant, names the week.
    $newYork = fn (int $day, int $hour = 0) => PlanFixtures::date(2026, 1, $day, $hour, 0, 'America/New_York');
    $grid = new PlanWeekGrid($newYork(5), PlanFixtures::MONDAY);
    $weighIns = [$newYork(6, 7), $newYork(9, 7)];
    $flagged = [
        new StoredPlanWeek(start: reviewTzUtc('2026-01-05 00:00'), isDeload: true),
        new StoredPlanWeek(start: reviewTzUtc('2026-01-12 00:00'), isDietBreak: true),
    ];
    $reviewed = [new StoredPlanWeek(start: reviewTzUtc('2026-01-05 00:00'), reviewedAt: reviewTzUtc('2026-01-12 03:00'))];

    $period = ReviewTiming::status($newYork(12, 9), $grid, $flagged, $weighIns)->period();

    expect($period->reviewed->isDeload)->toBeTrue()
        ->and($period->next->isDietBreak)->toBeTrue()
        ->and(ReviewTiming::status($newYork(12, 9), $grid, $reviewed, $weighIns)->isDone())->toBeTrue();
});

it('REV-003 a UTC weigh-in is filed under its Brisbane day', function () {
    $week = new PlanWeek(reviewTzBrisbane(5));
    // 20:00 UTC on 5 January is 06:00 on Tuesday 6 January in Brisbane.
    $rows = ReviewWeighIns::rows($week, [], [new WeighIn('w1', reviewTzUtc('2026-01-05 20:00'), 81.5)]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->day->format('Y-m-d'))->toBe('2026-01-06')
        ->and($rows[0]->weightKg)->toBe(81.5);
});

it('REV-002 a late save counts the days already past in the Brisbane day, not the UTC day', function () {
    $world = F::world(zone: REVIEW_TZ_BRISBANE);
    // 13:00 UTC on Wednesday 21 January is 23:00 on Wednesday in Brisbane: Monday and Tuesday are past, Wednesday is not.
    $today = reviewTzUtc('2026-01-21 13:00');

    // NUT-024: a move on Tuesday is refused, and one on Wednesday is allowed.
    $refusal = W::refusal(fn () => ReviewNextWeek::forSave(
        $world, NextWeekCalories::planned(), null, [new CalorieMove(1, 5, 100)], today: $today,
    ));
    $saved = ReviewNextWeek::forSave($world, NextWeekCalories::planned(), null, [new CalorieMove(2, 5, 100)], today: $today);

    expect($refusal->reason)->toBe(WeekBuildRefusalReason::Move)
        ->and($refusal->move->reason)->toBe(MoveRefusalReason::DayCompleted)
        ->and($refusal->move->dayIndex)->toBe(1)
        ->and($saved->days)->toHaveCount(7);
});

it('NUT-024 REV-004 the preview\'s past-day lock is the New York day forSave refuses moves on, not the UTC day', function () {
    $world = F::world(zone: 'America/New_York');
    $zone = $world->plan->start->getTimezone();
    // 02:00 UTC on Wednesday 21 January is 21:00 on Tuesday 20 January in New York: only Monday is past there, while on
    // the UTC calendar Monday and Tuesday are.
    $today = reviewTzUtc('2026-01-21 02:00');
    $week = ReviewNextWeek::build($world, NextWeekCalories::planned());

    $locked = array_map(fn ($day) => ReviewNextWeek::isPast($day->day->date, $today, $zone), $week->days);
    $refused = array_map(function (int $index) use ($world, $today) {
        try {
            ReviewNextWeek::forSave($world, NextWeekCalories::planned(), null, [new CalorieMove($index, 6, 25)], today: $today);

            return false;
        } catch (WeekBuildRefusal $refusal) {
            return $refusal->move?->reason === MoveRefusalReason::DayCompleted;
        }
    }, range(0, 5));

    expect($locked)->toBe([true, false, false, false, false, false, false])
        ->and($refused)->toBe(array_slice($locked, 0, 6))
        ->and(ReviewNextWeek::isPast($week->days[1]->day->date, $today, new DateTimeZone('UTC')))->toBeTrue();
});
