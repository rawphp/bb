<?php

use App\Actions\Mesocycles\CopyMesocycle;
use App\Actions\Mesocycles\TakeActiveRest;
use App\Capabilities\Reads\Train\MesocyclePayload;
use App\Domain\Defaults\TBL012TrainingDefaults\Column;
use App\Domain\Mesocycle\MesoBuilder;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Priority;
use App\Models\Mesocycle;
use App\Support\Mesocycles\TrainSnapshot;
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

it('MESO-064 refuses a past mesocycle that is not the one prompting, and changes neither', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    $user = TrainSeed::user();
    $past = TrainSeed::mesocycle($user, ['name' => 'Meso 1', 'start_date' => '2026-06-01']);
    $mesocycle = TrainSeed::mesocycle($user);
    TrainSeed::trained($mesocycle, 3, 1, '2026-09-21', flat: true);
    TrainSeed::trained($mesocycle, 4, 1, '2026-09-28', flat: true);

    expect(fn () => app(TakeActiveRest::class)($user, $past->id))
        ->toThrow(DomainException::class, 'An active rest is recommended only after flat weeks, from the deload week on.')
        ->and($past->fresh()->active_rest)->toBeFalse()
        ->and($mesocycle->fresh()->active_rest)->toBeFalse();
});

it('MESO-064 MESO-060 MESO-003 a copy made to start with the next mesocycle moves a week later, into the phase covering it', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    $user = TrainSeed::user();
    $gain = TrainSeed::phase($user, 'muscleGain', '2026-08-31', '2026-10-19');
    $cut = TrainSeed::phase($user, 'fatLoss', '2026-10-19');
    $mesocycle = TrainSeed::mesocycle($user, ['phase_id' => $gain->id]);
    TrainSeed::trained($mesocycle, 3, 1, '2026-09-21', flat: true);
    TrainSeed::trained($mesocycle, 4, 1, '2026-09-28', flat: true);
    $copy = '01JD0000000000000000000001';
    app(CopyMesocycle::class)($user, null, $copy, $mesocycle->id, null, true);

    $moved = app(TakeActiveRest::class)($user, $mesocycle->id);
    $draft = Mesocycle::query()->findOrFail($copy);

    // 12 October is still muscle gain, so keeping chose nothing: the copy is as one made without keep, and like one
    // made now on 19 October it takes the fat loss preset, with the offer to keep the old ones again.
    $preset = array_map(fn (Priority $p) => $p->value, MesoBuilder::defaultPriorities(Column::for(PhaseType::FatLoss, false)));

    expect($moved)->toBe([$copy])
        ->and($draft->start_date->format('Y-m-d'))->toBe('2026-10-19')
        ->and($draft->phase_id)->toBe($cut->id)
        ->and($draft->muscle_priorities)->toEqual($preset);
});

it('MESO-064 MESO-065 a moved copy keeps the priorities the user kept or edited', function (string $cutFrom, bool $keep, ?array $edited) {
    Carbon::setTestNow('2026-10-06 10:00:00');
    $user = TrainSeed::user();
    $gain = TrainSeed::phase($user, 'muscleGain', '2026-08-31', $cutFrom);
    $cut = TrainSeed::phase($user, 'fatLoss', $cutFrom);
    $mesocycle = TrainSeed::mesocycle($user, ['phase_id' => $gain->id]);
    TrainSeed::trained($mesocycle, 3, 1, '2026-09-21', flat: true);
    TrainSeed::trained($mesocycle, 4, 1, '2026-09-28', flat: true);
    $copy = '01JD0000000000000000000001';
    app(CopyMesocycle::class)($user, null, $copy, $mesocycle->id, null, $keep);
    if ($edited !== null) {
        Mesocycle::query()->findOrFail($copy)->update(['muscle_priorities' => $edited]);
    }
    $before = Mesocycle::query()->findOrFail($copy)->muscle_priorities;

    app(TakeActiveRest::class)($user, $mesocycle->id);
    $draft = Mesocycle::query()->findOrFail($copy);

    expect($draft->start_date->format('Y-m-d'))->toBe('2026-10-19')
        ->and($draft->phase_id)->toBe($cut->id)
        ->and($draft->muscle_priorities)->toBe($before)
        ->and($draft->muscle_priorities)->toEqual($edited ?? $mesocycle->muscle_priorities);
})->with([
    // Fat loss from 12 October: the copy is offered the preset and keeps Meso 2's priorities instead.
    'kept' => ['2026-10-12', true, null],
    // Fat loss from 19 October: the copy starts in muscle gain with Meso 2's priorities, and the user changes them.
    'edited' => ['2026-10-19', false, ['chest' => 'maintain', 'back' => 'emphasize']],
]);

it('MESO-064 MESO-065 a copy moved into a phase of another type takes that phase\'s preset, and the draft says so', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    $user = TrainSeed::user();
    $gain = TrainSeed::phase($user, 'muscleGain', '2026-08-31', '2026-10-19');
    TrainSeed::phase($user, 'fatLoss', '2026-10-19');
    $mesocycle = TrainSeed::mesocycle($user, ['phase_id' => $gain->id]);
    TrainSeed::trained($mesocycle, 3, 1, '2026-09-21', flat: true);
    TrainSeed::trained($mesocycle, 4, 1, '2026-09-28', flat: true);
    $copy = '01JD0000000000000000000001';
    // 12 October is still muscle gain, so the copy keeps the old priorities.
    app(CopyMesocycle::class)($user, null, $copy, $mesocycle->id, null, false);
    $copied = Mesocycle::query()->findOrFail($copy)->muscle_priorities;

    app(TakeActiveRest::class)($user, $mesocycle->id);
    $draft = Mesocycle::query()->findOrFail($copy);
    $preset = array_map(fn (Priority $p) => $p->value, MesoBuilder::defaultPriorities(Column::for(PhaseType::FatLoss, false)));
    $shown = MesocyclePayload::build(TrainSnapshot::load($user, $copy), $draft)->toArray()['build'];

    expect($copied)->toBe($mesocycle->muscle_priorities)
        ->and($draft->muscle_priorities)->toEqual($preset)
        ->and($shown['priority_notice'])->toBe(['text' => 'Priorities reset for Fat loss. Your old priorities can be kept.', 'tone' => 'info']);
});

it('MESO-064 MESO-065 a copy of an older mesocycle at the next start is not the one waiting, and stays as it is', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    $user = TrainSeed::user();
    $gain = TrainSeed::phase($user, 'muscleGain', '2026-05-25', '2026-10-19');
    TrainSeed::phase($user, 'fatLoss', '2026-10-19');
    $older = TrainSeed::mesocycle($user, ['name' => 'Meso 1', 'start_date' => '2026-06-01', 'phase_id' => $gain->id,
        'muscle_priorities' => ['chest' => 'maintain', 'back' => 'emphasize']]);
    $mesocycle = TrainSeed::mesocycle($user, ['phase_id' => $gain->id]);
    TrainSeed::trained($mesocycle, 3, 1, '2026-09-21', flat: true);
    TrainSeed::trained($mesocycle, 4, 1, '2026-09-28', flat: true);
    $copy = '01JD0000000000000000000001';
    // Dated at Meso 2's next start, but a copy of Meso 1: it does not follow Meso 2's active rest.
    app(CopyMesocycle::class)($user, null, $copy, $older->id, '2026-10-12', false);
    $before = Mesocycle::query()->findOrFail($copy)->getAttributes();

    expect(app(TakeActiveRest::class)($user, $mesocycle->id))->toBe([])
        ->and(Mesocycle::query()->findOrFail($copy)->getAttributes())->toBe($before)
        ->and($before['copied_from_id'])->toBe($older->id);
});

it('MESO-064 a draft starting elsewhere, or not a copy, stays where it is', function () {
    Carbon::setTestNow('2026-10-06 10:00:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    TrainSeed::trained($mesocycle, 3, 1, '2026-09-21', flat: true);
    TrainSeed::trained($mesocycle, 4, 1, '2026-09-28', flat: true);
    $draft = TrainSeed::mesocycle($user, ['is_draft' => true, 'route' => 'copy_previous', 'start_date' => '2026-11-02']);
    // A scratch draft dated at the next mesocycle's start is the user's own plan, not a copy waiting for it.
    $scratch = TrainSeed::mesocycle($user, ['is_draft' => true, 'route' => 'scratch', 'start_date' => '2026-10-12']);
    $scratchBefore = $scratch->fresh()->getAttributes();

    expect(app(TakeActiveRest::class)($user, $mesocycle->id))->toBe([])
        ->and($draft->fresh()->start_date->format('Y-m-d'))->toBe('2026-11-02')
        ->and($scratch->fresh()->getAttributes())->toBe($scratchBefore);
});
