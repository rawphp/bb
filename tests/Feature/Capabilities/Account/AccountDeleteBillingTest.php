<?php

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\BillingOn;

// account_delete with billing on (SUB-021): a subscription Stripe would still renew must be cancelled first, since
// the account and the subscription row that links it to Stripe go together, and nothing could cancel it afterwards.
uses()->group('warp-isolated', BillingOn::GROUP);

function deleteWithBilling(User $user): mixed
{
    return test()->actingAs($user)
        ->postJson('/capabilities/account_delete', ['confirmation' => 'DELETE'], ['Idempotency-Key' => (string) Str::ulid()]);
}

it('ACC-005 SUB-007 refuses to delete an account whose subscription would renew, and says to cancel it first', function (string $status) {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create(['status' => $status, 'cancel_at_period_end' => false]);

    deleteWithBilling($user)
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'Cancel your subscription in Manage subscription first, then delete your account.');

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue()
        ->and(Subscription::query()->where('user_id', $user->id)->exists())->toBeTrue();
})->with(['trialing', 'active', 'past_due']);

it('ACC-005 deletes an account whose subscription ends at the end of its period', function () {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create(['status' => 'active', 'cancel_at_period_end' => true]);

    deleteWithBilling($user)->assertOk();

    expect(User::query()->whereKey($user->id)->exists())->toBeFalse();
});

it('ACC-005 SUB-022 deletes an account whose subscription has ended, or that never subscribed', function (?string $status) {
    $user = User::factory()->create();
    Subscription::factory()->for($user)->create(['status' => $status]);

    deleteWithBilling($user)->assertOk();

    expect(User::query()->whereKey($user->id)->exists())->toBeFalse()
        ->and(Subscription::query()->where('user_id', $user->id)->exists())->toBeFalse();
})->with(['canceled' => 'canceled', 'unpaid' => 'unpaid', 'checkout only' => null]);
