<?php

use App\Billing\StripeGateway;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\BillingOn;
use Tests\Support\FakeStripe;

// account_delete with billing on (SUB-021): a subscription Stripe would still renew must be cancelled first, since
// the account and the subscription row that links it to Stripe go together, and nothing could cancel it afterwards.
// Stripe decides which subscriptions renew, not the stored row, which holds only the one the sync picked.
uses()->group('warp-isolated', BillingOn::GROUP);

beforeEach(function () {
    $this->stripe = new FakeStripe;
    $this->app->instance(StripeGateway::class, $this->stripe);
});

const DELETE_CUSTOMER = 'cus_delete_test';

/** A user with a Stripe customer, whose subscriptions Stripe lists as given. */
function customerWith(array $subscriptions): User
{
    $user = User::factory()->create();
    // The stored row is the subscription the sync picked: here the first one listed.
    Subscription::factory()->for($user)->create([
        'stripe_customer_id' => DELETE_CUSTOMER,
        'status' => $subscriptions[0]['status'] ?? null,
        'cancel_at_period_end' => $subscriptions[0]['cancel_at_period_end'] ?? false,
    ]);
    test()->stripe->subscriptions[DELETE_CUSTOMER] = $subscriptions;

    return $user;
}

function deleteWithBilling(User $user): mixed
{
    return test()->actingAs($user)
        ->postJson('/capabilities/account_delete', ['confirmation' => 'DELETE'], ['Idempotency-Key' => (string) Str::ulid()]);
}

it('ACC-005 SUB-007 refuses to delete an account whose subscription would renew, and says to cancel it first', function (string $status) {
    $user = customerWith([['id' => 'sub_a', 'status' => $status, 'cancel_at_period_end' => false]]);

    deleteWithBilling($user)
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'Cancel your subscription in Manage subscription first, then delete your account.');

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue()
        ->and(Subscription::query()->where('user_id', $user->id)->exists())->toBeTrue();
})->with(['trialing', 'active', 'past_due']);

it('ACC-005 SUB-007 a second subscription that would renew stops the deletion, though the stored one is set to end', function () {
    $user = customerWith([
        ['id' => 'sub_b', 'status' => 'active', 'cancel_at_period_end' => true],
        ['id' => 'sub_a', 'status' => 'active', 'cancel_at_period_end' => false],
    ]);

    deleteWithBilling($user)->assertStatus(422);

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

it('ACC-005 deletes an account whose subscriptions all end at the end of their period', function () {
    $user = customerWith([['id' => 'sub_a', 'status' => 'active', 'cancel_at_period_end' => true]]);

    deleteWithBilling($user)->assertOk();

    expect(User::query()->whereKey($user->id)->exists())->toBeFalse();
});

it('ACC-005 SUB-022 deletes an account whose subscriptions have ended', function (string $status) {
    $user = customerWith([['id' => 'sub_a', 'status' => $status, 'cancel_at_period_end' => false]]);

    deleteWithBilling($user)->assertOk();

    expect(User::query()->whereKey($user->id)->exists())->toBeFalse()
        ->and(Subscription::query()->where('user_id', $user->id)->exists())->toBeFalse();
})->with(['canceled', 'unpaid', 'incomplete_expired']);

it('ACC-005 deletes an account that never subscribed, without asking Stripe', function () {
    $user = User::factory()->create();
    $this->stripe->down = true;

    deleteWithBilling($user)->assertOk();

    expect(User::query()->whereKey($user->id)->exists())->toBeFalse();
});

it('ACC-005 SUB-021 keeps the account when Stripe cannot say whether a subscription renews', function () {
    $user = customerWith([['id' => 'sub_a', 'status' => 'canceled', 'cancel_at_period_end' => false]]);
    $this->stripe->down = true;

    // A refusal, not an unsure answer: nothing was deleted, and the user can try again.
    deleteWithBilling($user)
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'Payments are unavailable right now. Try again shortly.');

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});
