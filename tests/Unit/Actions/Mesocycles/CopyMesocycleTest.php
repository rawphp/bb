<?php

use App\Actions\Mesocycles\CopyMesocycle;
use App\Domain\Defaults\TBL012TrainingDefaults\Column;
use App\Domain\Mesocycle\MesoBuilder;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Priority;
use App\Enums\MesocycleRoute;
use App\Models\Mesocycle;
use App\Models\Phase;
use App\Models\User;
use App\Models\WorkoutSession;
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
        ->and($copy->route)->toBe(MesocycleRoute::CopyPrevious)
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

it('MESO-061 MESO-062 the load carried is the first set lifted on the first day, not one skipped, unlogged or later', function () {
    [$user, $source] = copyWorld();
    $bench = $source->dayRows()[0]['slots'][0]['exercise_id'];
    $session = fn (int $day) => WorkoutSession::factory()->for($user)->create([
        'mesocycle_id' => $source->id, 'week' => 1, 'day' => $day,
        'actual_start_at' => '2026-09-0'.(6 + $day).' 17:30:00', 'actual_end_at' => '2026-09-0'.(6 + $day).' 18:30:00',
    ]);
    $set = fn (WorkoutSession $s, int $position, float $kg, bool $logged = true, bool $skipped = false) => WorkoutSet::factory()->create([
        'workout_session_id' => $s->id, 'exercise_id' => $bench, 'position' => $position,
        'actual_weight_kg' => $kg, 'actual_reps' => 10, 'is_logged' => $logged, 'is_skipped' => $skipped,
    ]);
    $set($session(2), 1, 90.0);
    $day1 = $session(1);
    $set($day1, 1, 100.0, skipped: true);
    $set($day1, 2, 95.0, logged: false);
    $set($day1, 3, 81.0);

    expect(copyOf($user, $source)->dayRows()[0]['slots'][0]['carried'])->toBe(['weight_kg' => 81.0, 'reps' => 10]);
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

it('MESO-060 MESO-004 a copy of a mesocycle finished weeks ago starts at the default start, not in the past', function () {
    [$user, $source] = copyWorld('2026-11-03');

    expect(copyOf($user, $source)->start_date->format('Y-m-d'))->toBe('2026-11-09');
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

it('MESO-007 a copy replaces a row saved without dates too, as it is unfinished', function () {
    [$user, $source] = copyWorld();
    $undated = TrainSeed::mesocycle($user, ['start_date' => null, 'weeks' => null]);

    expect(app(CopyMesocycle::class)($user, null, COPY, $source->id, null, false))->toBe([$undated->id])
        ->and(Mesocycle::withTrashed()->find($undated->id)->trashed())->toBeTrue()
        ->and($source->fresh()->trashed())->toBeFalse();
});

it('MESO-007 refuses to copy over a saved mesocycle', function () {
    [$user, $source] = copyWorld();

    expect(fn () => app(CopyMesocycle::class)($user, $source, $source->id, $source->id, null, false))
        ->toThrow(DomainException::class, 'That mesocycle is already saved. Copy into a new one.');
});

it('MESO-003 MESO-065 another user\'s phase covering the start is not the copy\'s', function () {
    // The other user's muscle gain phase starts earlier and runs on, so it would come first if the user were ignored.
    TrainSeed::phase(User::factory()->create(), 'muscleGain', '2026-01-01');
    [$user, $source] = copyWorld(cutFrom: '2026-10-12');
    $cut = Phase::query()->where('user_id', $user->id)->where('type', 'fatLoss')->sole();
    $copy = copyOf($user, $source);

    expect($copy->phase_id)->toBe($cut->id)
        ->and($copy->muscle_priorities)->toEqual(array_map(
            fn (Priority $p) => $p->value,
            MesoBuilder::defaultPriorities(Column::for(PhaseType::FatLoss, false)),
        ));
});

it('MESO-007 CAP-019 a copy into the user\'s own row saved without dates makes that row the draft', function () {
    [$user, $source] = copyWorld();
    $undated = TrainSeed::mesocycle($user, ['start_date' => null, 'weeks' => null]);

    $replaced = app(CopyMesocycle::class)($user, $undated, $undated->id, $source->id, null, false);
    $draft = $undated->fresh();

    expect($replaced)->toBe([])
        ->and($draft->is_draft)->toBeTrue()
        ->and($draft->route)->toBe(MesocycleRoute::CopyPrevious)
        ->and(array_column($draft->dayRows(), 'label'))->toBe(['Upper A', 'Lower A'])
        ->and($draft->start_date->format('Y-m-d'))->toBe('2026-10-12');
});
