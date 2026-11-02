<?php

use App\Actions\Mesocycles\AddMesocycleDay;
use App\Models\Mesocycle;
use App\Models\Profile;
use App\Support\Mesocycles\TrainSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\TrainSeed;

// The action's job is what it saves, so it runs on the database. Meso 2 runs 5 weeks from Monday 7 September,
// "Upper A" on Monday and "Lower A" on Thursday; the user trains Monday and Thursday.
uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

const NEW_DAY = '01JC0000000000000000000000';

function addDay(array $meso = [], string $today = '2026-09-22', ?array $trainingDays = null): array
{
    Carbon::setTestNow("{$today} 10:00:00");
    $mesocycle = TrainSeed::mesocycle(TrainSeed::user(), $meso);
    if ($trainingDays !== null) {
        Profile::query()->update(['training_days' => array_map(fn (int $d) => ['weekday' => $d, 'start' => '17:30', 'minutes' => 60], $trainingDays)]);
    }
    try {
        app(AddMesocycleDay::class)($mesocycle->user, $mesocycle->id, NEW_DAY);
        $refusal = null;
    } catch (DomainException $refused) {
        $refusal = $refused->getMessage();
    }

    return [$mesocycle->fresh(), $refusal];
}

function floatingDays(): array
{
    return array_map(fn (array $day) => array_replace($day, ['weekday' => null]), TrainSeed::mesocycle(TrainSeed::user())->days);
}

it('MESO-045 appends an empty day at the end and keeps the others where they are', function () {
    [$mesocycle, $refusal] = addDay();
    $days = $mesocycle->dayRows();

    expect($refusal)->toBeNull()
        ->and(array_column($days, 'id'))->toBe(['01JB6Q400A2B3C4D5E6F7G8H9J', '01JB6Q410A2B3C4D5E6F7G8H9J', NEW_DAY])
        ->and($days[2]['slots'])->toBe([])
        ->and($mesocycle->weeks)->toBe(5);
});

it('MESO-045 a floating split gets the next day number', function () {
    [$mesocycle] = addDay(['days' => floatingDays()]);

    expect($mesocycle->dayRows()[2])->toMatchArray(['label' => 'Day 3', 'weekday' => null]);
});

it('MESO-045 a weekday split gets the next free training weekday', function () {
    // The user now trains Monday, Thursday and Friday; the mesocycle uses Monday and Thursday.
    [$mesocycle] = addDay(trainingDays: [1, 4, 5]);

    expect($mesocycle->dayRows()[2])->toMatchArray(['label' => 'Friday', 'weekday' => 5]);
});

it('MESO-045 a weekday split with no free training day refuses in plain words and changes nothing', function () {
    [$mesocycle, $refusal] = addDay();

    expect($refusal)->toBe('Every training day already has a session. Add a training day in your schedule first.')
        ->and(count($mesocycle->dayRows()))->toBe(2);
});

it('MESO-045 SCH-039 a day added in week 3 runs from week 3 on; weeks 1 and 2 keep their two days', function () {
    [$mesocycle] = addDay(['days' => floatingDays()]);
    $domain = TrainSnapshot::load($mesocycle->user, $mesocycle->id)->domain($mesocycle);

    expect(array_map(fn (int $week) => count($domain->sessionsInWeek($week)), range(0, 4)))->toBe([2, 2, 3, 3, 3]);
});

it('MESO-045 SCH-039 later weeks still ahead run the new day too; the ones passed keep theirs', function () {
    $upper = '01JB6Q400A2B3C4D5E6F7G8H9J';
    $lower = '01JB6Q410A2B3C4D5E6F7G8H9J';
    [$mesocycle] = addDay(['days' => floatingDays(), 'later_weeks' => [[$upper], [$lower], [$upper, $lower], [$upper]]]);

    expect(array_map(fn (array $week) => array_column($week, 'id'), $mesocycle->earlierWeekRows()))->toBe([[$upper], [$lower]])
        ->and($mesocycle->laterWeekIds())->toBe([[$upper, $lower, NEW_DAY], [$upper, NEW_DAY]]);
});

it('MESO-045 adding the same day again changes nothing', function () {
    [$mesocycle] = addDay();
    app(AddMesocycleDay::class)($mesocycle->user, $mesocycle->id, NEW_DAY);

    expect(count($mesocycle->fresh()->dayRows()))->toBe(3);
});

it('MESO-045 no day can be added once the mesocycle is over', function () {
    [$mesocycle, $refusal] = addDay(today: '2026-10-20');

    expect($refusal)->toBe('Days can be added only while the mesocycle runs.')
        ->and(count($mesocycle->dayRows()))->toBe(2)
        ->and(Mesocycle::query()->count())->toBe(1);
});
