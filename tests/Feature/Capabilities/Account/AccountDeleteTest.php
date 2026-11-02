<?php

use App\Agent\Exclusions;
use App\Capabilities\Support\HostDefinition;
use App\Models\AuditRecord;
use App\Models\DayTarget;
use App\Models\Phase;
use App\Models\Profile;
use App\Models\User;
use App\Models\WeighIn;
use Illuminate\Support\Facades\DB;
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

it('ACC-005 answers 422 and deletes nothing unless DELETE is typed exactly', function (string $typed) {
    $user = User::factory()->create();

    accountDelete($user, ['confirmation' => $typed])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'confirmation');

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
})->with(['delete', 'Delete', 'DELET', 'DELETE ', ' DELETE', 'DELETE!', '']);

it('ACC-005 deletes the account and all its data, and no one else\'s', function () {
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

it('ACC-005 deleting the account ends the session', function () {
    $user = User::factory()->create();

    accountDelete($user, ['confirmation' => 'DELETE'])->assertOk();

    $this->assertGuest('web');
    $this->postJson('/capabilities/account_show')->assertUnauthorized();
});

it('ACC-005 AGT-010 CAP-031 deleting the account is destructive, and only a person may do it', function () {
    $definition = app(CapabilityRegistry::class)->get('account_delete');

    expect(HostDefinition::isDestructive($definition))->toBeTrue()
        ->and(HostDefinition::isReversible($definition))->toBeFalse()
        ->and(Exclusions::reason('account_delete'))->not->toBeNull()
        ->and($definition->description)->toContain('cannot be undone');
});
