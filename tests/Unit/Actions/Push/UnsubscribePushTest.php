<?php

use App\Actions\Push\UnsubscribePush;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

// The action's job is the row it removes from push_subscriptions, so it runs on the database.
uses(RefreshDatabase::class);

it('NOTIF-010 removes the user\'s subscription for that browser and keeps their others', function () {
    $user = User::factory()->create();
    [$gone, $kept] = PushSubscription::factory()->for($user)->count(2)->create();

    (new UnsubscribePush)($user, $gone->endpoint);

    expect($user->pushSubscriptions()->pluck('id')->all())->toBe([$kept->id]);
});

it('NOTIF-010 never removes another user\'s subscription', function () {
    [$user, $other] = User::factory()->count(2)->create();
    $theirs = PushSubscription::factory()->for($other)->create();

    (new UnsubscribePush)($user, $theirs->endpoint);

    expect(PushSubscription::query()->pluck('id')->all())->toBe([$theirs->id]);
});

it('NOTIF-010 is fine with nothing to remove', function () {
    (new UnsubscribePush)(User::factory()->create(), 'https://fcm.googleapis.com/fcm/send/none');

    expect(PushSubscription::query()->count())->toBe(0);
});
