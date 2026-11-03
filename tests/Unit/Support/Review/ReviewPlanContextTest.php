<?php

use App\Domain\Model\PhaseType;
use App\Domain\WeekBuilder\AwayWeek\Calories;
use App\Domain\WeekBuilder\AwayWeek\Training;
use App\Models\Plan;
use App\Support\Review\ReviewData;
use App\Support\Review\ReviewPlanContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\ReviewSeed;

// rp-clone ReviewPlanContext: the stored plan read as the domain sees it, from ReviewSeed's rows. The plan starts
// Monday 14 September 2026 and reviews on Mondays; plan week 3 is 5 to 11 October, week 4 starts on the 12th.

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow(ReviewSeed::NOW));

/** @param  Closure(ReviewSeed): void  $change */
function reviewContext(?Closure $change = null): array
{
    $seed = new ReviewSeed;
    if ($change !== null) {
        $change($seed);
    }
    $data = ReviewData::load($seed->seed(), ReviewSeed::TODAY);

    return [ReviewPlanContext::from($data) ?? throw new RuntimeException('No plan.'), $data];
}

it('PLAN-002 a week\'s phase is the one covering it', function () {
    [$context] = reviewContext(fn (ReviewSeed $seed) => $seed->phaseEndDate = '2026-10-12');

    expect($context->phase(0)?->type)->toBe(PhaseType::FatLoss)
        ->and($context->phase(3)?->type)->toBe(PhaseType::FatLoss)
        ->and($context->phase(4))->toBeNull()
        ->and($context->grid->start(3)->format('Y-m-d'))->toBe('2026-10-05');
});

it('PLAN-022 SCH-060 a flagged week carries its row\'s marks and the deload its mesocycle puts there', function () {
    [$context] = reviewContext(function (ReviewSeed $seed) {
        $seed->mesocycleWeeks = 5;
        $seed->planWeekRows = [['start_date' => '2026-09-21', 'is_diet_break' => true]];
    });

    expect($context->row(new DateTimeImmutable('2026-09-21', new DateTimeZone('UTC')))?->is_diet_break)->toBeTrue()
        ->and($context->row(new DateTimeImmutable('2026-09-28', new DateTimeZone('UTC'))))->toBeNull()
        ->and($context->flagged(1, PhaseType::FatLoss)->isDietBreak)->toBeTrue()
        ->and($context->flagged(3, PhaseType::FatLoss)->isDeload)->toBeFalse()
        ->and($context->flagged(4, PhaseType::FatLoss)->isDeload)->toBeTrue();
});

it('SCH-060 PLAN-022 a paused away week before the mesocycle\'s last week moves its deload a week later', function () {
    $away = ['start_date' => '2026-09-21', 'is_away' => true, 'away_training' => 'pause', 'away_calories' => 'maintenance'];
    [$plain] = reviewContext(fn (ReviewSeed $seed) => $seed->mesocycleWeeks = 5);
    [$paused] = reviewContext(function (ReviewSeed $seed) use ($away) {
        $seed->mesocycleWeeks = 5;
        $seed->planWeekRows = [$away];
    });

    expect($plain->coveringMesocycle(4, PhaseType::FatLoss)['pausedWeeks'])->toBe([])
        ->and($plain->flagged(4, PhaseType::FatLoss)->isDeload)->toBeTrue()
        ->and($plain->flagged(5, PhaseType::FatLoss)->isDeload)->toBeFalse()
        ->and($paused->coveringMesocycle(4, PhaseType::FatLoss)['pausedWeeks'])->toBe([1])
        ->and($paused->flagged(4, PhaseType::FatLoss)->isDeload)->toBeFalse()
        ->and($paused->flagged(5, PhaseType::FatLoss)->isDeload)->toBeTrue();
});

it('REV-021 the finished weeks carry their average target and the calories checked in on each logged day', function () {
    [$context, $data] = reviewContext(fn (ReviewSeed $seed) => $seed->isChecked = fn (string $date, int $meal) => ! ($date === '2026-10-06' || ($date === '2026-10-07' && $meal === 1)));
    $weeks = $context->reviewWeeks(3, 2, PhaseType::FatLoss, $data->meals);

    expect($weeks)->toHaveCount(2)
        ->and($weeks[0]->week->start->format('Y-m-d'))->toBe('2026-09-28')
        ->and($weeks[1]->targetKcal)->toEqualWithDelta(2400, 1e-6)
        ->and($weeks[1]->loggedIntakeKcal)->toEqualWithDelta([2600, 2600 * 3 / 4, 2250, 2600, 2250, 2250], 1e-6)
        ->and($context->reviewWeeks(5, 12, PhaseType::FatLoss, $data->meals))->toHaveCount(4);
});

it('SCH-064 a finished week carries its stored rough mark and nothing else of it', function () {
    [$context, $data] = reviewContext(fn (ReviewSeed $seed) => $seed->planWeekRows = [['start_date' => '2026-10-05', 'is_rough' => true]]);
    $weeks = $context->reviewWeeks(3, 12, PhaseType::FatLoss, $data->meals, fn () => true);

    expect(array_map(fn ($week) => $week->isRough, $weeks))->toBe([false, false, false, true])
        ->and($weeks[3]->trainingFatigueHigh)->toBeTrue()
        ->and($weeks[3]->isBreakOrAway())->toBeFalse()
        ->and($weeks[3]->week)->toEqual($context->flagged(3, PhaseType::FatLoss));
});

it('REV-002 WT-011 SCH-060 next week\'s world: the profile at the trend weight, the schedule and what its row marks', function () {
    [$context, $data] = reviewContext(fn (ReviewSeed $seed) => $seed->planWeekRows = [
        ['start_date' => '2026-10-12', 'is_away' => true, 'away_training' => 'deload', 'away_calories' => 'rest_days'],
    ]);
    $world = $context->world($data->profile, 87.25, 4, PhaseType::FatLoss);

    expect($world->profile->weightKg)->toBe(87.25)
        ->and($world->nextWeek)->toBe(4)
        ->and($world->mesocycleStartWeek)->toBe(0)
        ->and($world->mesocycle->weeks)->toBe(6)
        ->and($world->away?->training)->toBe(Training::Deload)
        ->and($world->away?->calories)->toBe(Calories::RestDays)
        ->and(array_map(fn ($day) => $day->weekday, $world->schedule->trainingDays))->toBe([2, 4, 6])
        ->and($world->schedule->nutrition?->mealsPerDay)->toBe(4)
        ->and($world->schedule->nutrition?->steps)->toHaveCount(7);
});

it('PLAN-008 PLAN-002 the review reads its weeks on the grid with the stored review-day change', function () {
    // From Monday 28 September the plan reviews on Thursdays: week 2 runs 10 days, to 7 October, and week 3 starts
    // Thursday 8 October, so today (12 October) is in week 3.
    $user = (new ReviewSeed)->seed();
    Plan::query()->where('user_id', $user->id)->firstOrFail()
        ->reviewDayChanges()->create(['effective_on' => '2026-09-28', 'review_weekday' => 4]);

    $context = ReviewPlanContext::from(ReviewData::load($user, ReviewSeed::TODAY)) ?? throw new RuntimeException('No plan.');

    expect($context->plan->reviewDayChanges)->toHaveCount(1)
        ->and($context->grid->start(1)->format('Y-m-d'))->toBe('2026-09-21')
        ->and($context->grid->start(2)->format('Y-m-d'))->toBe('2026-09-28')
        ->and($context->grid->lengthInDays(2))->toBe(10)
        ->and($context->grid->start(3)->format('Y-m-d'))->toBe('2026-10-08')
        ->and($context->grid->weekIndexContaining(new DateTimeImmutable('2026-10-12', new DateTimeZone('UTC'))))->toBe(3);
});
