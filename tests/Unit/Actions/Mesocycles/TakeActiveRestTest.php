<?php

use App\Actions\Mesocycles\TakeActiveRest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\TrainSeed;

// The action's job is what it saves, so it runs on the database. When it is recommended is TrainPayloadTest's.
// Meso 2 runs 5 weeks from Monday 7 September; its deload week starts on 5 October.
uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function activeRest(string $today, bool $flat): array
{
    Carbon::setTestNow("{$today} 10:00:00");
    $mesocycle = TrainSeed::mesocycle(TrainSeed::user());
    TrainSeed::trained($mesocycle, 3, 1, '2026-09-21', flat: $flat);
    TrainSeed::trained($mesocycle, 4, 1, '2026-09-28', flat: $flat);
    try {
        app(TakeActiveRest::class)($mesocycle->user, $mesocycle->id);
        $refusal = null;
    } catch (DomainException $refused) {
        $refusal = $refused->getMessage();
    }

    return [$mesocycle->fresh(), $refusal];
}

it('MESO-064 takes the active rest the deload week recommends', function () {
    [$mesocycle, $refusal] = activeRest('2026-10-06', flat: true);

    expect($refusal)->toBeNull()
        ->and($mesocycle->active_rest)->toBeTrue();
});

it('MESO-064 refuses an active rest that is not recommended, or before the deload week', function (string $today, bool $flat) {
    [$mesocycle, $refusal] = activeRest($today, $flat);

    expect($refusal)->toBe('An active rest is recommended only after flat weeks, from the deload week on.')
        ->and($mesocycle->active_rest)->toBeFalse();
})->with([
    'not flat' => ['2026-10-06', false],
    'before the deload' => ['2026-09-30', true],
]);
