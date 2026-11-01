<?php

use App\Models\PushSubscription;
use App\Models\User;

function subscription(array $overrides = []): array
{
    return [
        'endpoint' => 'https://push.example.com/send/device-1',
        'public_key' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM',
        'auth_token' => 'tBHItJI5svbpez7KI4CCXg',
        ...$overrides,
    ];
}

it('NOTIF-010 push_subscribe saves this device\'s subscription for the signed-in user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/capabilities/push_subscribe', subscription())
        ->assertOk()
        ->assertJsonPath('data.invalidates', ['push_show{}']);

    expect($user->pushSubscriptions()->sole())
        ->endpoint->toBe('https://push.example.com/send/device-1')
        ->auth_token->toBe('tBHItJI5svbpez7KI4CCXg');
});

it('NOTIF-010 subscribing the same device again updates its keys instead of adding a second row', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/capabilities/push_subscribe', subscription())->assertOk();
    $this->actingAs($user)->postJson('/capabilities/push_subscribe', subscription(['auth_token' => 'new-token']))->assertOk();

    expect($user->pushSubscriptions()->sole()->auth_token)->toBe('new-token');
});

it('NOTIF-010 a device that switches accounts receives the new account\'s notifications only', function () {
    [$before, $after] = User::factory()->count(2)->create();

    $this->actingAs($before)->postJson('/capabilities/push_subscribe', subscription())->assertOk();
    $this->actingAs($after)->postJson('/capabilities/push_subscribe', subscription())->assertOk();

    expect($before->pushSubscriptions()->count())->toBe(0)
        ->and($after->pushSubscriptions()->count())->toBe(1);
});

it('NOTIF-010 refuses an endpoint that is not an https URL', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/push_subscribe', subscription(['endpoint' => 'http://push.example.com/x']))
        ->assertStatus(422);
});

it('NOTIF-010 push_unsubscribe removes the caller\'s subscription and leaves other users\' alone', function () {
    [$user, $other] = User::factory()->count(2)->create();
    $this->actingAs($user)->postJson('/capabilities/push_subscribe', subscription())->assertOk();
    $this->actingAs($other)->postJson('/capabilities/push_subscribe', subscription(['endpoint' => 'https://push.example.com/send/device-2']))->assertOk();

    $this->actingAs($user)
        ->postJson('/capabilities/push_unsubscribe', ['endpoint' => 'https://push.example.com/send/device-2'])
        ->assertOk();
    expect($other->pushSubscriptions()->count())->toBe(1);

    $this->actingAs($user)
        ->postJson('/capabilities/push_unsubscribe', ['endpoint' => 'https://push.example.com/send/device-1'])
        ->assertOk()
        ->assertJsonPath('data.invalidates', ['push_show{}']);
    expect($user->pushSubscriptions()->count())->toBe(0);
});

it('NOTIF-010 push_show gives the VAPID public key and how many devices are subscribed', function () {
    config(['webpush.vapid.public_key' => 'public-key', 'webpush.vapid.private_key' => 'private-key']);
    $user = User::factory()->create();
    $this->actingAs($user)->postJson('/capabilities/push_subscribe', subscription())->assertOk();

    $this->actingAs($user)
        ->postJson('/capabilities/push_show')
        ->assertOk()
        ->assertJsonPath('data', ['available' => true, 'public_key' => 'public-key', 'devices' => 1]);
});

it('NOTIF-010 push_show says notifications are unavailable when the instance has no VAPID keys', function () {
    config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);

    $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/push_show')
        ->assertOk()
        ->assertJsonPath('data', ['available' => false, 'public_key' => null, 'devices' => 0]);
});

it('NOTIF-010 refuses push capabilities when signed out', function (string $name) {
    $this->postJson("/capabilities/{$name}", subscription())->assertUnauthorized();
})->with(['push_show', 'push_subscribe', 'push_unsubscribe']);

it('ACC-005 deleting a user deletes their push subscriptions', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->postJson('/capabilities/push_subscribe', subscription())->assertOk();

    $user->delete();

    expect(PushSubscription::query()->count())->toBe(0);
});
