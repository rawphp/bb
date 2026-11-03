<?php

use App\Actions\Mesocycles\AddMesocycleDay;
use App\Actions\Mesocycles\FillMesocycleSlots;
use App\Actions\Mesocycles\GenerateMesocycleStructure;
use App\Actions\Mesocycles\SetMusclePriority;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\Priority;
use App\Models\Exercise;
use App\Models\Mesocycle;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\TrainSeed;

// A mesocycle write that waited on the row lock reads what the write before it saved. A reversible write runs inside
// UndoLog's transaction, which has read before the action locks, and under MySQL's REPEATABLE READ a plain read keeps
// that first view; the action reads with locks, which see the latest commit. The other write is committed through a
// second connection, so this file runs without RefreshDatabase and removes what it made.
// Meso 2 runs 5 weeks from Monday 7 September; Tuesday 22 September is week 3.

afterEach(fn () => Carbon::setTestNow());

it('MESO-032 MESO-034 a mesocycle write keeps the change another connection committed after the transaction\'s first read', function (array $attributes, Closure $race, Closure $write, Closure $kept) {
    Carbon::setTestNow('2026-09-22 10:00:00');
    $default = DB::getDefaultConnection();
    config(['database.connections.racer' => config("database.connections.{$default}")]);
    $racer = DB::connection('racer');
    $exercisesBefore = (int) Exercise::query()->max('id');
    DB::setDefaultConnection('racer');
    try {
        $user = TrainSeed::user();
        // Friday is a free training day for the day added.
        $user->profile()->update(['training_days' => array_map(fn (int $d) => ['weekday' => $d, 'start' => '17:30', 'minutes' => 60], [1, 4, 5])]);
        TrainSeed::exercise('Standing Calf Raise', MuscleGroup::Calves);
        $mesocycle = TrainSeed::mesocycle($user, ['phase_id' => TrainSeed::phase($user, 'muscleGain', '2026-08-31')->id, ...$attributes]);
    } finally {
        DB::setDefaultConnection($default);
    }
    $owner = User::query()->findOrFail($user->id);

    try {
        DB::transaction(function () use ($racer, $owner, $mesocycle, $race, $write) {
            DB::table('users')->count();
            $race($racer, $mesocycle);
            $write($owner, $mesocycle->id);
        });
        $saved = Mesocycle::query()->findOrFail($mesocycle->id);
    } finally {
        $racer->table('users')->where('id', $owner->id)->delete();
        $racer->table('exercises')->where('id', '>', $exercisesBefore)->delete();
        DB::purge('racer');
    }

    $kept($saved);
})->with([
    'priority_set keeps another muscle\'s priority' => [
        [],
        fn (Connection $racer, Mesocycle $m) => $racer->table('mesocycles')->where('id', $m->id)
            ->update(['muscle_priorities' => json_encode([...$m->muscle_priorities, 'triceps' => 'maintain'])]),
        fn (User $user, string $id) => app(SetMusclePriority::class)($user, $id, MuscleGroup::Biceps, Priority::Emphasize),
        fn (Mesocycle $saved) => expect($saved->muscle_priorities)->toMatchArray(['triceps' => 'maintain', 'biceps' => 'emphasize']),
    ],
    'slots filled keep another write\'s day' => [
        ['is_draft' => true, 'start_date' => '2026-10-12'],
        fn (Connection $racer, Mesocycle $m) => $racer->table('mesocycles')->where('id', $m->id)
            ->update(['days' => json_encode([array_replace($m->days[0], ['label' => 'Push']), $m->days[1]])]),
        fn (User $user, string $id) => expect(app(FillMesocycleSlots::class)($user, $id))->toBe(1),
        fn (Mesocycle $saved) => expect($saved->days[0]['label'])->toBe('Push')
            ->and($saved->days[1]['slots'][1]['exercise_id'])->not->toBeNull(),
    ],
    'structure generated takes another write\'s days per week' => [
        ['is_draft' => true, 'route' => 'builder', 'name' => null, 'weeks' => null, 'start_date' => null, 'days' => [], 'muscle_priorities' => [], 'days_per_week' => 3],
        fn (Connection $racer, Mesocycle $m) => $racer->table('mesocycles')->where('id', $m->id)->update(['days_per_week' => 4]),
        fn (User $user, string $id) => app(GenerateMesocycleStructure::class)($user, $id),
        fn (Mesocycle $saved) => expect($saved->days)->toHaveCount(4),
    ],
    'day added keeps another write\'s day' => [
        [],
        fn (Connection $racer, Mesocycle $m) => $racer->table('mesocycles')->where('id', $m->id)
            ->update(['days' => json_encode([array_replace($m->days[0], ['label' => 'Push']), $m->days[1]])]),
        fn (User $user, string $id) => app(AddMesocycleDay::class)($user, $id, '01JC0000000000000000000000'),
        fn (Mesocycle $saved) => expect($saved->days)->toHaveCount(3)
            ->and($saved->days[0]['label'])->toBe('Push'),
    ],
]);
