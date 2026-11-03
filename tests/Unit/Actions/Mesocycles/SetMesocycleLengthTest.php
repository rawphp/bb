<?php

use App\Actions\Mesocycles\SetMesocycleLength;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\TrainSeed;

// The action's job is what it saves, so it runs on the database. Which lengths are allowed is MesocycleCalendarTest's.
// Meso 2 runs 5 weeks from Monday 7 September; Tuesday 22 September is week 3.
uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function lengthSet(string $today, int $weeks, array $meso = [], int $expected = 5): array
{
    Carbon::setTestNow("{$today} 10:00:00");
    $mesocycle = TrainSeed::mesocycle(TrainSeed::user(), $meso);
    $before = $mesocycle->fresh()->getAttributes();
    try {
        app(SetMesocycleLength::class)($mesocycle->user, $mesocycle->id, $weeks, $expected);
        $refusal = null;
    } catch (DomainException $refused) {
        $refusal = $refused->getMessage();
    }

    return [$mesocycle->fresh(), $refusal, $before];
}

it('MESO-042 plus adds a week and the deload stays last, keeping the days and the end', function () {
    [$mesocycle, $refusal] = lengthSet('2026-09-22', 6, ['ended_on' => '2026-10-20']);

    expect($refusal)->toBeNull()
        ->and($mesocycle->weeks)->toBe(6)
        ->and($mesocycle->ended_on->format('Y-m-d'))->toBe('2026-10-20')
        ->and(array_column($mesocycle->dayRows(), 'label'))->toBe(['Upper A', 'Lower A']);
});

it('MESO-042 refuses a change when the length moved since the screen was loaded, and goes ahead when it did not', function () {
    // Another device took Meso 2 from 5 to 6 weeks; a minus on a screen still showing 5 sends 4.
    [$mesocycle, $refusal, $before] = lengthSet('2026-09-22', 4, ['weeks' => 6], expected: 5);

    expect($refusal)->toBe('This mesocycle\'s length changed to 6 weeks since the screen was loaded, so nothing was changed.')
        ->and($mesocycle->getAttributes())->toBe($before);

    [$mesocycle, $refusal] = lengthSet('2026-09-22', 5, ['weeks' => 6], expected: 6);

    expect($refusal)->toBeNull()
        ->and($mesocycle->weeks)->toBe(5);
});

it('MESO-042 minus removes the last training week', function () {
    expect(lengthSet('2026-09-22', 4)[0]->weeks)->toBe(4);
});

it('MESO-042 refuses a length outside the current week plus one to eight, naming the weeks, and writes nothing', function (int $weeks) {
    [$mesocycle, $refusal, $before] = lengthSet('2026-09-22', $weeks);

    expect($refusal)->toBe('This mesocycle is in week 3, so it can run 4 to 8 weeks.')
        ->and($mesocycle->getAttributes())->toBe($before);
})->with([3, 9]);

it('MESO-042 refuses during the deload week, once over, and while later weeks are stored', function (string $today, array $meso, string $message) {
    [$mesocycle, $refusal] = lengthSet($today, 6, $meso);

    expect($refusal)->toBe($message)
        ->and($mesocycle->weeks)->toBe(5);
})->with([
    'deload week' => ['2026-10-06', [], 'The deload week has started, so the weeks can\'t change.'],
    'over' => ['2026-10-20', [], 'This mesocycle is over, so its weeks can\'t change.'],
    'later weeks' => ['2026-09-22', ['later_weeks' => [['01JB6Q400A2B3C4D5E6F7G8H9J']]], 'The weeks were rearranged when your training days changed, so their number can\'t change.'],
]);

it('MESO-042 refuses to run into the next saved mesocycle, saying when it starts', function () {
    // Meso 2 ends on Sunday 11 October; Meso 3 starts the next day.
    Carbon::setTestNow('2026-09-22 10:00:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    TrainSeed::mesocycle($user, ['name' => 'Meso 3', 'start_date' => '2026-10-12']);

    expect(fn () => app(SetMesocycleLength::class)($user, $mesocycle->id, 6, 5))
        ->toThrow(DomainException::class, 'The next mesocycle starts on 12 Oct, so this one can run at most 5 weeks.')
        ->and($mesocycle->fresh()->weeks)->toBe(5);
});

it('MESO-042 a copy waiting for the next start, and an earlier mesocycle, do not stop it lengthening', function () {
    Carbon::setTestNow('2026-09-22 10:00:00');
    $user = TrainSeed::user();
    TrainSeed::mesocycle($user, ['name' => 'Meso 1', 'start_date' => '2026-06-01']);
    $mesocycle = TrainSeed::mesocycle($user);
    TrainSeed::mesocycle($user, ['name' => null, 'is_draft' => true, 'route' => 'copy_previous', 'start_date' => '2026-10-12']);

    app(SetMesocycleLength::class)($user, $mesocycle->id, 6, 5);

    expect($mesocycle->fresh()->weeks)->toBe(6);
});

it('MESO-042 a second length change from a stale screen is refused once the first has saved, because the check reads the mesocycle row under a lock', function () {
    Carbon::setTestNow('2026-09-22 10:00:00');
    $mesocycle = TrainSeed::mesocycle(TrainSeed::user());

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(SetMesocycleLength::class)($mesocycle->user, $mesocycle->id, 6, 5);
    DB::disableQueryLog();
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::flushQueryLog();

    $onMesocycles = array_values(preg_grep('/`mesocycles`/', $queries));
    $selects = array_values(preg_grep('/^select /', $onMesocycles));
    expect(preg_grep('/for update$/', $onMesocycles))->toHaveCount(1)
        ->and($onMesocycles[0])->toEndWith('for update')
        ->and(array_filter($selects, fn (string $sql) => ! preg_match('/(for update|lock in share mode)$/', $sql)))->toBe([])
        ->and($mesocycle->fresh()->weeks)->toBe(6);

    $before = $mesocycle->fresh()->getAttributes();

    expect(fn () => app(SetMesocycleLength::class)($mesocycle->user, $mesocycle->id, 4, 5))
        ->toThrow(DomainException::class, 'This mesocycle\'s length changed to 6 weeks since the screen was loaded, so nothing was changed.')
        ->and($mesocycle->fresh()->getAttributes())->toBe($before);
});
