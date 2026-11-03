<?php

use App\Actions\Settings\SaveProfile;
use App\Domain\Plan\PlanRebuildRefusal;
use App\Domain\Plan\PlanRebuildRefused;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRebuild;

// SET-004: a settings write saves the profile, then rebuilds the targets from the next day when it touched a target
// input or a rebuild is pending. Ported from rp-clone's SettingsModelTests (the rebuild internals are RebuildTargets').
// The rebuild is a stand-in, so these tests show only when it runs and what a failure leaves behind.
uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-10-14 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

function saveProfileUser(array $profile = []): User
{
    $user = User::factory()->create();
    Profile::factory()->for($user)->create(array_replace(['meal_count' => 4], $profile));

    return $user;
}

function saveProfile(User $user, array $changes): array
{
    return app(SaveProfile::class)($user, $changes);
}

function storedProfile(User $user): Profile
{
    return Profile::query()->where('user_id', $user->id)->firstOrFail();
}

it('SET-004 a changed date of birth saves the profile, then rebuilds from the next day', function () {
    $rebuild = FakeRebuild::bind();
    $user = saveProfileUser();

    $dates = saveProfile($user, ['birth_date' => '1985-01-02']);

    expect($rebuild->calls)->toBe([['user' => $user->id, 'beforeStart' => true]])
        ->and($dates)->toBe(['2026-10-15'])
        ->and(storedProfile($user)->birth_date->format('Y-m-d'))->toBe('1985-01-02')
        ->and(storedProfile($user)->targets_rebuild_failed_at)->toBeNull();
});

it('SET-004 a changed appearance saves and does not rebuild', function () {
    $rebuild = FakeRebuild::bind();
    $user = saveProfileUser();

    expect(saveProfile($user, ['appearance' => 'dark']))->toBe([])
        ->and($rebuild->calls)->toBe([])
        ->and(storedProfile($user)->appearance)->toBe('dark');
});

it('SET-004 only the settings that never reach the targets skip the rebuild', function () {
    $rebuild = FakeRebuild::bind();
    $user = saveProfileUser();

    saveProfile($user, [
        'name' => 'Someone else', 'weight_unit' => 'lb', 'height_unit' => 'feetInches', 'appearance' => 'light',
        'consumed_totals' => 'checked_in', 'micro_plates' => true, 'diet_filters' => ['vegan'],
        'weigh_in_days' => [['weekday' => 1, 'time' => '06:00']],
    ]);

    expect($rebuild->calls)->toBe([]);
});

it('SET-004 a weight, schedule or other profile change rebuilds', function (array $changes) {
    $rebuild = FakeRebuild::bind();
    $user = saveProfileUser();

    saveProfile($user, $changes);

    expect($rebuild->calls)->toHaveCount(1);
})->with([
    'sex' => [['sex' => 'male']],
    'height' => [['height_cm' => 180.0]],
    'body fat' => [['body_fat_bucket' => 'high']],
    'GLP-1' => [['uses_glp1' => true]],
    'experience' => [['experience' => 'advanced']],
    'meal count' => [['meal_count' => 5]],
    'training days' => [['training_days' => [['weekday' => 3, 'start' => '17:30', 'minutes' => 60]]]],
]);

it('SET-004 a save with no change does not rebuild', function () {
    $rebuild = FakeRebuild::bind();
    $user = saveProfileUser();

    saveProfile($user, ['birth_date' => '1990-04-12', 'height_cm' => 168.0]);

    expect($rebuild->calls)->toBe([]);
});

it('SET-004 a save with no change rebuilds while a rebuild is pending ("Tap Save to try again"), and clears it', function () {
    $rebuild = FakeRebuild::bind();
    $user = saveProfileUser(['targets_rebuild_failed_at' => '2026-10-13 09:00:00']);

    saveProfile($user, []);

    expect($rebuild->calls)->toHaveCount(1)
        ->and(storedProfile($user)->targets_rebuild_failed_at)->toBeNull();
});

it('SET-004 a rebuild the rules refuse keeps the saved profile and the old targets, and clears the pending rebuild', function () {
    FakeRebuild::bind(new PlanRebuildRefused(PlanRebuildRefusal::planNotStarted()));
    $user = saveProfileUser(['targets_rebuild_failed_at' => '2026-10-13 09:00:00']);

    expect(saveProfile($user, ['experience' => 'advanced']))->toBe([]);

    $profile = storedProfile($user);
    expect($profile->experience)->toBe('advanced')
        ->and($profile->meal_count)->toBe(4)
        ->and($profile->targets_rebuild_failed_at)->toBeNull();
});

it('SET-004 a rebuild that fails keeps the saved profile, rolls back only the rebuild, records the failure and reports it', function () {
    $failure = new RuntimeException('the database went away');
    FakeRebuild::bind($failure);
    $reported = [];
    app(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$reported) {
        $reported[] = $e;

        return false;
    });
    $user = saveProfileUser();

    expect(saveProfile($user, ['experience' => 'advanced']))->toBe([]);

    $profile = storedProfile($user);
    expect($profile->experience)->toBe('advanced')
        ->and($profile->meal_count)->toBe(4)
        ->and($profile->targets_rebuild_failed_at?->toDateTimeString())->toBe('2026-10-14 12:00:00')
        ->and($reported)->toBe([$failure]);
});

it('SET-004 a deadlock or lock wait in the rebuild fails the whole write rather than counting as a failed rebuild', function () {
    // MySQL has rolled back the whole transaction by then, profile save included, so nothing may be recorded as saved.
    FakeRebuild::bind(new DeadlockException('Deadlock found when trying to get lock; try restarting transaction'));
    $reported = [];
    app(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$reported) {
        $reported[] = $e;

        return false;
    });

    expect(fn () => saveProfile(saveProfileUser(), ['experience' => 'advanced']))->toThrow(DeadlockException::class)
        ->and($reported)->toBe([]);
});

it('SET-004 a deadlock the database reports as a query error also fails the whole write', function () {
    FakeRebuild::bind(new QueryException('mysql', 'select 1', [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction')));
    $reported = [];
    app(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$reported) {
        $reported[] = $e;

        return false;
    });

    expect(fn () => saveProfile(saveProfileUser(), ['experience' => 'advanced']))
        ->toThrow(fn (Exception $e) => expect((new ConcurrencyErrorDetector)->causedByConcurrencyError($e))->toBeTrue())
        ->and($reported)->toBe([]);
});

it('SET-004 the plan row is locked before the profile row, the order RebuildTargets takes them in', function () {
    FakeRebuild::bind();
    $user = saveProfileUser();
    $locks = [];
    DB::listen(function ($query) use (&$locks) {
        if (str_contains($query->sql, 'for update')) {
            $locks[] = preg_match('/from [`"]?(\w+)/', $query->sql, $m) === 1 ? $m[1] : $query->sql;
        }
    });

    saveProfile($user, ['experience' => 'advanced']);

    expect(array_slice($locks, 0, 2))->toBe(['plans', 'profiles']);
});

it('SET-004 a rebuild that runs after a failure clears the notice', function () {
    FakeRebuild::bind(new RuntimeException('down'));
    $user = saveProfileUser();
    saveProfile($user, ['experience' => 'advanced']);
    expect(storedProfile($user)->targets_rebuild_failed_at)->not->toBeNull();

    FakeRebuild::bind();
    saveProfile($user, []);

    expect(storedProfile($user)->targets_rebuild_failed_at)->toBeNull();
});

it('SET-004 a profile change made before a check refuses saves nothing and does not rebuild', function () {
    $rebuild = FakeRebuild::bind();
    $user = saveProfileUser();

    expect(fn () => app(SaveProfile::class)($user, ['experience' => 'advanced'], fn () => throw new DomainException('no')))
        ->toThrow(DomainException::class, 'no');

    expect(storedProfile($user)->experience)->toBe('intermediate')
        ->and($rebuild->calls)->toBe([]);
});

it('SET-004 a write that changed a target input outside the profile rebuilds with no profile field changed, one that did not does not', function () {
    $rebuild = FakeRebuild::bind();
    $user = saveProfileUser();

    $none = app(SaveProfile::class)($user, ['birth_date' => '1990-04-12'], null, fn (): bool => false);
    expect($none)->toBe([])->and($rebuild->calls)->toBe([]);

    $order = [];
    $dates = app(SaveProfile::class)($user, ['birth_date' => '1990-04-12'], function () use (&$order): void {
        $order[] = 'check';
    }, function () use (&$order): bool {
        $order[] = 'write';

        return true;
    });

    expect($dates)->toBe(['2026-10-15'])
        ->and($rebuild->calls)->toBe([['user' => $user->id, 'beforeStart' => true]])
        ->and($order)->toBe(['check', 'write']);
});

it('SET-004 a user with no profile yet is told to finish setup', function () {
    FakeRebuild::bind();

    expect(fn () => saveProfile(User::factory()->create(), ['name' => 'Sam']))
        ->toThrow(DomainException::class, SaveProfile::NO_PROFILE);
});
