<?php

use App\Actions\Settings\SaveProfile;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRebuild;

// SET-004: a deadlock inside the rebuild means MySQL rolled back the whole write, so SaveProfile tries it again. Only the
// outermost transaction retries, so this file runs without RefreshDatabase's wrapping transaction and removes what it made.

beforeEach(fn () => Carbon::setTestNow('2026-10-14 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('SET-004 a rebuild that deadlocks once is retried with the whole write, and the profile ends saved', function () {
    $user = User::factory()->create();
    Profile::factory()->for($user)->create(['experience' => 'intermediate', 'meal_count' => 4]);
    $rebuild = FakeRebuild::bind(new DeadlockException('Deadlock found when trying to get lock; try restarting transaction'), times: 1);

    try {
        expect(DB::transactionLevel())->toBe(0)
            ->and(app(SaveProfile::class)($user, ['experience' => 'advanced']))->toBe(['2026-10-15']);

        $profile = Profile::query()->where('user_id', $user->id)->firstOrFail();
        // Two runs: the first deadlocked, and its write (meal count 6) went with the rollback.
        expect($rebuild->calls)->toHaveCount(2)
            ->and($profile->experience)->toBe('advanced')
            ->and($profile->meal_count)->toBe(4)
            ->and($profile->targets_rebuild_failed_at)->toBeNull();
    } finally {
        $user->delete();
    }
});

it('SET-004 a rebuild that keeps deadlocking fails the write after three tries, and saves nothing', function () {
    $user = User::factory()->create();
    Profile::factory()->for($user)->create(['experience' => 'intermediate']);
    $rebuild = FakeRebuild::bind(new DeadlockException('Deadlock found when trying to get lock; try restarting transaction'));

    try {
        expect(fn () => app(SaveProfile::class)($user, ['experience' => 'advanced']))->toThrow(DeadlockException::class)
            ->and($rebuild->calls)->toHaveCount(3)
            ->and(Profile::query()->where('user_id', $user->id)->value('experience'))->toBe('intermediate')
            ->and(Profile::query()->where('user_id', $user->id)->value('targets_rebuild_failed_at'))->toBeNull();
    } finally {
        $user->delete();
    }
});
