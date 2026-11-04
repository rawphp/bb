<?php

use App\Domain\Model\WeighIn as DomainWeighIn;
use App\Domain\Weight\CurrentWeight;
use App\Models\Profile;
use App\Models\User;
use App\Models\WeighIn;
use App\Support\Today\DayPlans;
use App\Support\Today\TodayWeight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

// WT-011 as the Today sheets read it: the trend of every weigh-in, worked out once and kept until a weigh-in changes,
// so a sheet read costs one summary row, not the whole history.
uses(RefreshDatabase::class);

function weighedUser(): User
{
    $user = User::factory()->create();
    Profile::factory()->for($user)->create(['weight_kg' => 80.0]);
    foreach (['2026-09-01' => 80.0, '2026-09-08' => 79.0, '2026-09-15' => 78.0] as $day => $kg) {
        WeighIn::factory()->for($user)->create(['weighed_at' => "{$day} 07:00:00", 'weight_kg' => $kg]);
    }

    return $user;
}

/** @return array{?float, list<string>} the weight, and the SQL its read ran */
function weightRead(User $user): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $kg = TodayWeight::kg($user, $user->profile);
    DB::disableQueryLog();

    return [$kg, array_column(DB::getQueryLog(), 'query')];
}

it('WT-011 a second read with no weigh-in changed reads one summary row, not the weigh-ins', function () {
    $user = weighedUser();
    [$first] = weightRead($user);
    [$second, $queries] = weightRead($user);

    expect($second)->toBe($first)
        ->and($queries)->toHaveCount(1)
        ->and($queries[0])->toContain('count(*)');
});

it('WT-011 a weigh-in added, edited or deleted changes the weight read next', function (Closure $change) {
    $user = weighedUser();
    [$before] = weightRead($user);

    $change($user);

    [$after] = weightRead($user);
    expect($after)->not->toBe($before)
        ->and($after)->toBe(CurrentWeight::fromWeighIns(
            WeighIn::query()->where('user_id', $user->id)->get()
                ->map(fn (WeighIn $row) => new DomainWeighIn((string) $row->id, DayPlans::instant($row->weighed_at), (float) $row->weight_kg))
                ->all(),
            80.0,
            new DateTimeZone('UTC'),
        ));
})->with([
    'added' => [fn (User $user) => WeighIn::factory()->for($user)->create(['weighed_at' => '2026-09-22 07:00:00', 'weight_kg' => 76.0])],
    'edited without touching updated_at' => [fn (User $user) => DB::table('weigh_ins')->where('user_id', $user->id)->whereDate('weighed_at', '2026-09-15')->update(['weight_kg' => 70.0])],
    'deleted' => [fn (User $user) => WeighIn::query()->where('user_id', $user->id)->whereDate('weighed_at', '2026-09-15')->delete()],
]);

it('WT-011 with no weigh-ins the profile\'s weight is the current weight', function () {
    $user = User::factory()->create();
    Profile::factory()->for($user)->create(['weight_kg' => 64.0]);

    expect(weightRead($user)[0])->toBe(64.0);
});

it('WT-011 one user\'s weight is never another\'s, whatever their weigh-ins hold', function () {
    $user = weighedUser();
    $other = weighedUser();
    WeighIn::query()->where('user_id', $other->id)->update(['weight_kg' => 50.0]);

    expect(weightRead($user)[0])->not->toBe(weightRead($other)[0]);
});

it('WT-011 trendKg is the cached trend kg() uses, one summary row once kept, and null with no weigh-ins', function () {
    $user = weighedUser();
    $kg = TodayWeight::kg($user, $user->profile);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $trendKg = TodayWeight::trendKg($user);
    DB::disableQueryLog();

    $none = User::factory()->create();
    Profile::factory()->for($none)->create(['weight_kg' => 64.0]);
    expect($trendKg)->toBe($kg)
        ->and(DB::getQueryLog())->toHaveCount(1)
        ->and(TodayWeight::trendKg($none))->toBeNull();
});

it('WT-008 trendByDay is the trend on each local day, kept as trendKg is: one summary row once kept, empty with no weigh-ins', function () {
    $user = weighedUser();
    $user->forceFill(['time_zone' => 'Australia/Brisbane'])->save();
    WeighIn::factory()->for($user)->create(['weighed_at' => '2026-09-15 23:30:00', 'weight_kg' => 70.0]);
    $byDay = TodayWeight::trendByDay($user);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $kept = TodayWeight::trendByDay($user);
    DB::disableQueryLog();

    expect($kept)->toBe($byDay)
        ->and(DB::getQueryLog())->toHaveCount(1)
        ->and(array_key_first($byDay))->toBe('2026-09-01')
        ->and(array_key_last($byDay))->toBe('2026-09-16')
        ->and(end($byDay))->toBe(TodayWeight::trendKg($user))
        ->and(TodayWeight::trendByDay(User::factory()->create()))->toBe([]);
});
