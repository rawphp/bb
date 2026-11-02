<?php

use App\Actions\Push\SubscribePush;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

// The action's job is the row it keeps in push_subscriptions, so it runs on the database.
uses(RefreshDatabase::class);

const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/device-1';

it('NOTIF-010 saves the browser\'s subscription for the user', function () {
    $user = User::factory()->create();

    (new SubscribePush)($user, ENDPOINT, 'p256dh', 'auth');

    expect($user->pushSubscriptions()->sole())
        ->endpoint->toBe(ENDPOINT)
        ->public_key->toBe('p256dh')
        ->auth_token->toBe('auth');
});

it('NOTIF-010 keeps one row per endpoint and updates its keys', function () {
    $user = User::factory()->create();

    (new SubscribePush)($user, ENDPOINT, 'p256dh', 'auth');
    (new SubscribePush)($user, ENDPOINT, 'p256dh-2', 'auth-2');

    expect(PushSubscription::query()->sole())->auth_token->toBe('auth-2')->public_key->toBe('p256dh-2');
});

it('NOTIF-010 moves a browser to the account that subscribes it last', function () {
    [$before, $after] = User::factory()->count(2)->create();

    (new SubscribePush)($before, ENDPOINT, 'p256dh', 'auth');
    (new SubscribePush)($after, ENDPOINT, 'p256dh', 'auth');

    expect(PushSubscription::query()->sole()->user_id)->toBe($after->id);
});
