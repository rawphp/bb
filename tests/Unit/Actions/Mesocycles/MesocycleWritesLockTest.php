<?php

use App\Actions\Mesocycles\AddMesocycleDay;
use App\Actions\Mesocycles\FillMesocycleSlots;
use App\Actions\Mesocycles\GenerateMesocycleStructure;
use App\Actions\Mesocycles\SetMusclePriority;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\Priority;
use App\Models\Mesocycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TrainSeed;

// Each write reads the mesocycle, computes a whole JSON column and writes it back. Locking the row first makes two
// close writes (two replayed outbox entries, or the agent and the app) take turns, so neither loses the other's change.
// Meso 2 runs 5 weeks from Monday 7 September; Tuesday 22 September is week 3.
uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

it('MESO-032 MESO-034 each mesocycle write locks the mesocycle row before it reads it, so two writes take turns', function (Closure $seed, Closure $write) {
    Carbon::setTestNow('2026-09-22 10:00:00');
    $user = TrainSeed::user();
    // Friday is a free training day for the day added.
    $user->profile()->update(['training_days' => array_map(fn (int $d) => ['weekday' => $d, 'start' => '17:30', 'minutes' => 60], [1, 4, 5])]);
    $gain = TrainSeed::phase($user, 'muscleGain', '2026-08-31');
    $mesocycle = $seed($user, $gain->id);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $write($user, $mesocycle);
    $queries = array_values(preg_grep('/`mesocycles`/', array_column(DB::getQueryLog(), 'query')));
    // The warm app keeps the connection between tests, so the log is turned off again.
    DB::disableQueryLog();
    DB::flushQueryLog();

    expect(preg_grep('/for update/', $queries))->toHaveCount(1)
        ->and($queries[0])->toContain('for update')
        ->and($queries[0])->toContain('`user_id`');
})->with([
    'priority_set' => [
        fn ($user, string $phaseId) => TrainSeed::mesocycle($user, ['phase_id' => $phaseId]),
        fn ($user, Mesocycle $mesocycle) => app(SetMusclePriority::class)($user, $mesocycle->id, MuscleGroup::Biceps, Priority::Emphasize),
    ],
    'slots filled' => [
        fn ($user, string $phaseId) => TrainSeed::mesocycle($user, ['phase_id' => $phaseId, 'is_draft' => true, 'start_date' => '2026-10-12']),
        fn ($user, Mesocycle $mesocycle) => app(FillMesocycleSlots::class)($user, $mesocycle->id),
    ],
    'structure generated' => [
        fn ($user, string $phaseId) => TrainSeed::mesocycle($user, [
            'phase_id' => $phaseId, 'is_draft' => true, 'route' => 'builder', 'name' => null, 'weeks' => null, 'start_date' => null,
            'days' => [], 'muscle_priorities' => [], 'days_per_week' => 3,
        ]),
        fn ($user, Mesocycle $mesocycle) => app(GenerateMesocycleStructure::class)($user, $mesocycle->id),
    ],
    'day added' => [
        fn ($user, string $phaseId) => TrainSeed::mesocycle($user, ['phase_id' => $phaseId]),
        fn ($user, Mesocycle $mesocycle) => app(AddMesocycleDay::class)($user, $mesocycle->id, (string) Str::ulid()),
    ],
]);
