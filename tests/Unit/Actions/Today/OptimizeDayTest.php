<?php

use App\Actions\Today\OptimizeDay;
use App\Actions\Today\RetargetableDay;
use App\Capabilities\Reads\Data\Today\OptimizeRow;
use App\Capabilities\Today\Data\DayOptimizeInput;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SheetSeed;
use Tests\Support\TrainSeed;

// What day_optimize_show shows and day_optimize saves (SCH-036). The placement is RetargetTest's (optimize). Thursday
// 24 September 2026 holds Lower A at 5:30 PM; its meals are at 7:30 AM, 12:30 PM and 7:00 PM in a 7 AM to 8 PM window.
uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-09-22 08:00:00'));
afterEach(fn () => Carbon::setTestNow());

function optimizeWorld(): User
{
    $user = TrainSeed::user();
    TrainSeed::mesocycle($user);

    return $user;
}

/** @return array<string, ?string> each row's time after, by meal id */
function previewTimes(User $user, string $date = '2026-09-24'): array
{
    return collect(OptimizeDay::preview($user, $date)->rows)->mapWithKeys(fn (OptimizeRow $row) => [$row->meal_id => $row->after_text])->all();
}

it('SCH-036 previews each meal\'s time before and after around the day\'s session, and saves nothing', function () {
    $user = optimizeWorld();
    $day = SheetSeed::day($user, '2026-09-24');
    $before = $day->fresh()->only(['kcal', 'meals']);

    $preview = OptimizeDay::preview($user, '2026-09-24');

    $rows = collect($preview->rows);
    expect($preview->date)->toBe('2026-09-24')
        ->and($preview->can_save)->toBeTrue()
        ->and($preview->message)->toBeNull()
        ->and($rows->firstWhere('meal_id', SheetSeed::id(SheetSeed::BREAKFAST, '260924'))->before_text)->toBe('7:30 AM')
        ->and($rows->contains(fn (OptimizeRow $row) => $row->changed))->toBeTrue()
        ->and($rows->every(fn (OptimizeRow $row) => $row->changed === ($row->before_text !== $row->after_text)))->toBeTrue()
        ->and($day->fresh()->only(['kcal', 'meals']))->toEqual($before);
});

it('SCH-036 saves the result the preview showed for the same day, and then has nothing to change', function () {
    $user = optimizeWorld();
    $day = SheetSeed::day($user, '2026-09-24');
    $shown = previewTimes($user);

    $optimized = app(OptimizeDay::class)($user, new DayOptimizeInput('2026-09-24'));

    $saved = collect($day->fresh()->meals);
    $after = OptimizeDay::preview($user, '2026-09-24');
    expect($optimized['toast'])->toBe('Meals optimized')
        ->and($optimized['dates'])->toBe(['2026-09-24'])
        // The stored meals keep their ids; each sits where the preview put it.
        ->and($saved->mapWithKeys(fn (array $meal) => [$meal['id'] => $meal['time']])->only(array_keys($shown))->map(fn (string $time) => date('g:i A', strtotime($time)))->all())
        ->toEqual(collect($shown)->only($saved->pluck('id')->all())->all())
        ->and($saved->sum('kcal'))->toEqualWithDelta($day->fresh()->kcal, 0.5)
        ->and($after->can_save)->toBeFalse()
        ->and($after->message)->toBe(OptimizeDay::ALREADY_BEST)
        ->and(collect($after->rows)->every(fn (OptimizeRow $row) => ! $row->changed))->toBeTrue();
});

it('SCH-036 SCH-055 a checked-in meal keeps its time', function () {
    $user = optimizeWorld();
    $day = SheetSeed::day($user, '2026-09-24');
    $lunch = SheetSeed::id(SheetSeed::LUNCH, '260924');
    SheetSeed::checkIn($lunch);

    expect(previewTimes($user)[$lunch])->toBe('12:30 PM');

    app(OptimizeDay::class)($user, new DayOptimizeInput('2026-09-24'));

    expect(collect($day->fresh()->meals)->firstWhere('id', $lunch)['time'])->toBe('12:30');
});

it('SCH-036 a day with nothing to change cannot be saved', function () {
    $user = optimizeWorld();
    $day = SheetSeed::day($user, '2026-09-24');
    app(OptimizeDay::class)($user, new DayOptimizeInput('2026-09-24'));
    $before = $day->fresh()->meals;

    expect(fn () => app(OptimizeDay::class)($user, new DayOptimizeInput('2026-09-24')))->toThrow(DomainException::class, OptimizeDay::ALREADY_BEST)
        ->and($day->fresh()->meals)->toEqual($before);
});

it('SCH-036 a past day or a day with no meals shows why and cannot be saved', function (string $date, string $message) {
    $user = optimizeWorld();
    SheetSeed::day($user, '2026-09-21');

    $preview = OptimizeDay::preview($user, $date);

    expect([$preview->rows, $preview->message, $preview->can_save])->toBe([[], $message, false])
        ->and(fn () => app(OptimizeDay::class)($user, new DayOptimizeInput($date)))->toThrow(DomainException::class, $message);
})->with([
    'a past day' => ['2026-09-21', RetargetableDay::PAST],
    'a day with no targets' => ['2026-09-25', RetargetableDay::NO_MEALS],
]);
