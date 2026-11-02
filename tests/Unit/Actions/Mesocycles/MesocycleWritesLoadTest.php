<?php

use App\Actions\Mesocycles\AddMesocycleDay;
use App\Actions\Mesocycles\CopyMesocycle;
use App\Actions\Mesocycles\EndMesocycle;
use App\Actions\Mesocycles\SetMesocycleLength;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TrainSeed;

// What the mesocycle writes read: never the exercise library or the day targets, which only mesocycle_show shows.
// Meso 2 runs 5 weeks from Monday 7 September; Tuesday 22 September is week 3.
uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/** @return list<string> the SQL run by `$work` */
function queriesOf(Closure $work): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $work();
    DB::disableQueryLog();

    return array_column(DB::getQueryLog(), 'query');
}

it('MESO-042 MESO-043 MESO-045 MESO-060 the writes read neither the exercise library nor the day targets', function () {
    Carbon::setTestNow('2026-09-22 10:00:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);

    $queries = queriesOf(function () use ($user, $mesocycle) {
        app(SetMesocycleLength::class)($user, $mesocycle->id, 6);
        app(AddMesocycleDay::class)($user, $mesocycle->id, (string) Str::ulid());
        app(CopyMesocycle::class)($user, null, (string) Str::ulid(), $mesocycle->id, null, false);
        app(EndMesocycle::class)($user, $mesocycle->id, false);
    });

    expect(preg_grep('/`(exercises|day_targets)`/', $queries))->toBe([]);
});

it('MESO-040 a saved mesocycle looks up only the exercises it names', function () {
    Carbon::setTestNow('2026-09-22 10:00:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);

    $queries = queriesOf(fn () => test()->actingAs($user)
        ->postJson('/capabilities/mesocycle_show', ['mesocycle_id' => $mesocycle->id])->assertOk());

    $library = preg_grep('/from `exercises`/', $queries);
    expect($library)->not->toBe([])
        ->and(array_values(array_filter($library, fn (string $sql) => ! str_contains($sql, '`id` in ('))))->toBe([]);
});
