<?php

use App\Actions\Push\ClearPushForOtherAccounts;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

// The action's job is the row it removes from push_subscriptions, so it runs on the database.
uses(RefreshDatabase::class);

it('NOTIF-010 removes the previous account\'s subscription for the browser someone just signed in on', function () {
    [$previous, $current] = User::factory()->count(2)->create();
    $browser = PushSubscription::factory()->for($previous)->create();
    $elsewhere = PushSubscription::factory()->for($previous)->create();

    (new ClearPushForOtherAccounts)($current, $browser->endpoint);

    expect(PushSubscription::query()->pluck('id')->all())->toBe([$elsewhere->id]);
});

it('NOTIF-010 keeps the signed-in user\'s own subscription for that browser', function () {
    $user = User::factory()->create();
    $own = PushSubscription::factory()->for($user)->create();

    (new ClearPushForOtherAccounts)($user, $own->endpoint);

    expect(PushSubscription::query()->pluck('id')->all())->toBe([$own->id]);
});
