<?php

use App\Actions\Mesocycles\SetMesocycleLength;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\TrainSeed;

// The action's job is what it saves, so it runs on the database. Which lengths are allowed is MesocycleCalendarTest's.
// Meso 2 runs 5 weeks from Monday 7 September; Tuesday 22 September is week 3.
uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function lengthSet(string $today, int $weeks, array $meso = []): array
{
    Carbon::setTestNow("{$today} 10:00:00");
    $mesocycle = TrainSeed::mesocycle(TrainSeed::user(), $meso);
    $before = $mesocycle->fresh()->getAttributes();
    try {
        app(SetMesocycleLength::class)($mesocycle->user, $mesocycle->id, $weeks);
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

    expect(fn () => app(SetMesocycleLength::class)($user, $mesocycle->id, 6))
        ->toThrow(DomainException::class, 'The next mesocycle starts on 12 Oct, so this one can run at most 5 weeks.')
        ->and($mesocycle->fresh()->weeks)->toBe(5);
});
