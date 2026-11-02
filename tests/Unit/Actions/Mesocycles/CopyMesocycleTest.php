<?php

use App\Actions\Mesocycles\CopyMesocycle;
use App\Models\Mesocycle;
use App\Models\Phase;
use App\Models\User;
use App\Models\WorkoutSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\TrainSeed;

// The action's job is the draft it saves, so it runs on the database. Meso 2 runs 5 weeks from Monday 7 September
// under muscle gain; its deload week starts on 5 October.
uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

const COPY = '01JD0000000000000000000000';

/** @return array{User, Mesocycle} */
function copyWorld(string $today = '2026-10-06', ?string $cutFrom = null): array
{
    Carbon::setTestNow("{$today} 10:00:00");
    $user = TrainSeed::user();
    $gain = TrainSeed::phase($user, 'muscleGain', '2026-08-31', $cutFrom);
    if ($cutFrom !== null) {
        TrainSeed::phase($user, 'fatLoss', $cutFrom);
    }

    return [$user, TrainSeed::mesocycle($user, ['phase_id' => $gain->id])];
}

function copyOf(User $user, Mesocycle $source, ?string $start = null, bool $keep = false): Mesocycle
{
    app(CopyMesocycle::class)($user, Mesocycle::query()->find(COPY), COPY, $source->id, $start, $keep);

    return Mesocycle::query()->findOrFail(COPY);
}

it('MESO-060 copies the structure into a new draft with new ids', function () {
    [$user, $source] = copyWorld();
    $copy = copyOf($user, $source);
    $days = $copy->dayRows();
    $old = $source->dayRows();

    expect($copy->is_draft)->toBeTrue()
        ->and($copy->route)->toBe('copy_previous')
        ->and($copy->name)->toBeNull()
        ->and($copy->weeks)->toBe(5)
        ->and($copy->user_id)->toBe($user->id)
        ->and(array_column($days, 'label'))->toBe(['Upper A', 'Lower A'])
        ->and(array_column($days, 'weekday'))->toBe([1, 4])
        ->and(array_column($days[1]['slots'], 'muscle_group'))->toBe(['quads', 'calves'])
        ->and(array_column($days[1]['slots'], 'sets'))->toBe([3, 2])
        ->and(array_column($days[1]['slots'], 'exercise_id'))->toBe(array_column($old[1]['slots'], 'exercise_id'))
        ->and(array_intersect(array_column($days, 'id'), array_column($old, 'id')))->toBe([])
        ->and(array_column($days[0]['slots'], 'id'))->not->toBe(array_column($old[0]['slots'], 'id'))
        ->and($copy->muscle_priorities)->toBe($source->muscle_priorities);
});

it('MESO-061 MESO-062 each slot carries the old week-1 load of its exercise', function () {
    [$user, $source] = copyWorld();
    TrainSeed::trained($source, 1, 2, '2026-09-10');
    TrainSeed::trained($source, 2, 1, '2026-09-14');
    $days = copyOf($user, $source)->dayRows();

    // Week 1 logged Lower A's squat at 81 kg x 10 first; Upper A's bench only in week 2.
    expect($days[1]['slots'][0]['carried'])->toBe(['weight_kg' => 81.0, 'reps' => 10])
        ->and($days[1]['slots'][1]['carried'])->toBeNull()
        ->and($days[0]['slots'][0]['carried'])->toBeNull();
});

it('MESO-061 MESO-063 the copy carries the load as lifted, leaving the rise and the dumbbell hold to the planned targets', function () {
    [$user, $source] = copyWorld();
    $session = TrainSeed::trained($source, 1, 1, '2026-09-07');
    WorkoutSet::query()->where('workout_session_id', $session->id)->update(['actual_weight_kg' => 12.5, 'actual_reps' => 14]);

    // LoadProgression applies the copy rise, or keeps a light dumbbell's weight and chases reps, from `carried`.
    expect(copyOf($user, $source)->dayRows()[0]['slots'][0]['carried'])->toBe(['weight_kg' => 12.5, 'reps' => 14]);
});

it('MESO-060 a copy of the mesocycle ending starts when the next one does', function () {
    [$user, $source] = copyWorld();

    expect(copyOf($user, $source)->start_date->format('Y-m-d'))->toBe('2026-10-12');
});

it('MESO-060 MESO-064 with an active rest taken the copy starts a week later', function () {
    [$user, $source] = copyWorld();
    $source->update(['active_rest' => true]);

    expect(copyOf($user, $source)->start_date->format('Y-m-d'))->toBe('2026-10-19');
});

it('MESO-004 a copy before the deload starts next plan week, and a start given snaps to its plan week', function () {
    [$user, $source] = copyWorld('2026-09-23');

    expect(copyOf($user, $source)->start_date->format('Y-m-d'))->toBe('2026-09-28')
        ->and(copyOf($user, $source, '2026-11-04')->start_date->format('Y-m-d'))->toBe('2026-11-02');
});

it('MESO-003 the copy belongs to the phase covering its start', function () {
    [$user, $source] = copyWorld(cutFrom: '2026-10-12');
    $cut = Phase::query()->where('user_id', $user->id)->where('type', 'fatLoss')->sole();

    expect(copyOf($user, $source)->phase_id)->toBe($cut->id);
});

it('MESO-065 a copy into a phase of another type takes its priority preset, unless the old ones are kept', function () {
    [$user, $source] = copyWorld(cutFrom: '2026-10-12');
    $reset = copyOf($user, $source)->muscle_priorities;
    $kept = copyOf($user, $source, keep: true)->muscle_priorities;

    expect($reset)->toHaveCount(14)
        ->and(array_unique(array_values($reset)))->toBe(['emphasize'])
        ->and($kept)->toBe($source->muscle_priorities);
});

it('MESO-007 a new copy replaces the user\'s other draft, and the same id updates its own', function () {
    [$user, $source] = copyWorld();
    $old = TrainSeed::mesocycle($user, ['is_draft' => true, 'start_date' => null, 'weeks' => null]);
    $theirs = TrainSeed::mesocycle(User::factory()->create(), ['is_draft' => true]);

    copyOf($user, $source);
    $replaced = app(CopyMesocycle::class)($user, Mesocycle::query()->find(COPY), COPY, $source->id, '2026-10-19', false);

    expect($replaced)->toBe([])
        ->and(Mesocycle::query()->where('user_id', $user->id)->where('is_draft', true)->pluck('id')->all())->toBe([COPY])
        ->and(Mesocycle::withTrashed()->find($old->id)->trashed())->toBeTrue()
        ->and($theirs->fresh()->trashed())->toBeFalse()
        ->and(Mesocycle::query()->find(COPY)->start_date->format('Y-m-d'))->toBe('2026-10-19');
});

it('MESO-007 refuses to copy over a saved mesocycle', function () {
    [$user, $source] = copyWorld();

    expect(fn () => app(CopyMesocycle::class)($user, $source, $source->id, $source->id, null, false))
        ->toThrow(DomainException::class, 'That mesocycle is already saved. Copy into a new one.');
});
