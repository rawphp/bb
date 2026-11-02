<?php

use App\Agent\Exclusions;
use App\Capabilities\Support\HostDefinition;
use App\Models\AuditRecord;
use App\Models\DayTarget;
use App\Models\Phase;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WeighIn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\TrainSeed;

// account_delete over HTTP (ACC-005): the seam, and the account and its data going on the database. That a user's
// rows cascade with it is the schema's (every user_id cascades); here it is proven through the capability.

function accountDelete(?User $user, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/account_delete', $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

it('ACC-005 refuses account_delete when signed out', function () {
    accountDelete(null, ['confirmation' => 'DELETE'])->assertUnauthorized();
});

it('ACC-005 SET-009 answers 422 and deletes nothing unless DELETE is typed exactly', function (string $typed) {
    $user = User::factory()->create();

    accountDelete($user, ['confirmation' => $typed])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'confirmation');

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
})->with(['delete', 'Delete', 'DELET', 'DELETE ', ' DELETE', 'DELETE!', '']);

it('ACC-005 SET-009 deletes the account and all its data, and no one else\'s', function () {
    $user = TrainSeed::user();
    TrainSeed::mesocycle($user);
    Phase::factory()->for($user)->create();
    DayTarget::factory()->for($user)->create();
    WeighIn::factory()->for($user)->create();
    $other = TrainSeed::user();

    accountDelete($user, ['confirmation' => 'DELETE'])->assertOk()->assertJsonPath('data.undo', null);

    expect(User::query()->whereKey($user->id)->exists())->toBeFalse()
        ->and(Profile::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('phases')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('day_targets')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('mesocycles')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(WeighIn::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(AuditRecord::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(Profile::query()->where('user_id', $other->id)->exists())->toBeTrue();
});

it('ACC-005 SET-009 deleting the account ends the session that deleted it', function () {
    // Signed in as the PWA is: a session cookie on the app's own origin. Signing in sets a remember token, which a
    // plain logout would cycle by saving the user, and so put the deleted account back.
    $this->withHeader('Referer', config('app.url'));
    User::factory()->create(['email' => 'sam@example.com', 'password' => 'correct-horse-battery']);
    $this->postJson('/sign-in', ['email' => 'sam@example.com', 'password' => 'correct-horse-battery'])->assertNoContent();
    $this->startSession();
    $token = session()->token();

    accountDelete(null, ['confirmation' => 'DELETE'])->assertOk();

    $this->assertGuest('web');
    expect(session()->token())->not->toBe($token)
        ->and(User::query()->where('email', 'sam@example.com')->exists())->toBeFalse();
    // The next request starts with no guard resolved, as a new request to the server does.
    app('auth')->forgetGuards();
    $this->postJson('/capabilities/account_show')->assertUnauthorized();
});

it('ACC-005 CAP-022 the deletion is audited with no user, since the account is gone', function () {
    $user = User::factory()->create();
    Exceptions::fake();

    accountDelete($user, ['confirmation' => 'DELETE'])->assertOk();

    Exceptions::assertNothingReported();
    expect(AuditRecord::query()->where('capability', 'account_delete')->get(['user_id', 'channel'])->toArray())
        ->toBe([['user_id' => null, 'channel' => 'ui']]);
});

it('ACC-005 SUB-020 with billing off, a subscription row left from billing on does not stop the deletion', function () {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create(['status' => 'active', 'cancel_at_period_end' => false]);

    accountDelete($user, ['confirmation' => 'DELETE'])->assertOk();

    expect(User::query()->whereKey($user->id)->exists())->toBeFalse();
});

it('ACC-005 AGT-010 CAP-031 deleting the account is destructive, and only a person may do it', function () {
    $definition = app(CapabilityRegistry::class)->get('account_delete');

    expect(HostDefinition::isDestructive($definition))->toBeTrue()
        ->and(HostDefinition::isReversible($definition))->toBeFalse()
        ->and(Exclusions::reason('account_delete'))->not->toBeNull()
        ->and($definition->description)->toContain('cannot be undone');
});
