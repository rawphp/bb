<?php

use App\Models\PushSubscription;
use App\Models\User;

function subscription(array $overrides = []): array
{
    return [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/device-1',
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
        ->endpoint->toBe('https://fcm.googleapis.com/fcm/send/device-1')
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

it('NOTIF-010 refuses an endpoint that is not https on a known push service', function (string $endpoint) {
    $this->actingAs(User::factory()->create())
        ->postJson('/capabilities/push_subscribe', subscription(['endpoint' => $endpoint]))
        ->assertStatus(422);

    expect(PushSubscription::query()->count())->toBe(0);
})->with([
    'plain http' => 'http://fcm.googleapis.com/fcm/send/x',
    'internal address' => 'https://169.254.169.254/latest/meta-data',
    'private host' => 'https://10.0.0.5:8443/admin',
    'look-alike host' => 'https://fcm.googleapis.com.evil.example/send/x',
    'suffix without a dot' => 'https://evilpush.apple.com/x',
]);

it('NOTIF-010 accepts each browser\'s push service', function (string $endpoint) {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/capabilities/push_subscribe', subscription(['endpoint' => $endpoint]))->assertOk();

    expect($user->pushSubscriptions()->sole()->endpoint)->toBe($endpoint);
})->with([
    'Chrome' => 'https://fcm.googleapis.com/fcm/send/abc',
    'Firefox' => 'https://updates.push.services.mozilla.com/wpush/v2/abc',
    'Safari' => 'https://web.push.apple.com/QabcXYZ',
    'Edge' => 'https://wns2-par02p.notify.windows.com/w/?token=abc',
]);

it('NOTIF-010 push_unsubscribe removes the caller\'s subscription and leaves other users\' alone', function () {
    [$user, $other] = User::factory()->count(2)->create();
    $this->actingAs($user)->postJson('/capabilities/push_subscribe', subscription())->assertOk();
    $this->actingAs($other)->postJson('/capabilities/push_subscribe', subscription(['endpoint' => 'https://fcm.googleapis.com/fcm/send/device-2']))->assertOk();

    $this->actingAs($user)
        ->postJson('/capabilities/push_unsubscribe', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/device-2'])
        ->assertOk();
    expect($other->pushSubscriptions()->count())->toBe(1);

    $this->actingAs($user)
        ->postJson('/capabilities/push_unsubscribe', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/device-1'])
        ->assertOk()
        ->assertJsonPath('data.invalidates', ['push_show{}']);
    expect($user->pushSubscriptions()->count())->toBe(0);
});

it('NOTIF-010 push_show gives the VAPID public key and how many devices are subscribed', function () {
    config(['webpush.vapid.public_key' => 'public-key', 'webpush.vapid.private_key' => 'private-key']);
    [$user, $other, $none] = User::factory()->count(3)->create();
    $this->actingAs($user)->postJson('/capabilities/push_subscribe', subscription())->assertOk();
    $this->actingAs($other)->postJson('/capabilities/push_subscribe', subscription(['endpoint' => 'https://fcm.googleapis.com/fcm/send/device-2']))->assertOk();

    $this->actingAs($user)
        ->postJson('/capabilities/push_show')
        ->assertOk()
        ->assertJsonPath('data', ['available' => true, 'public_key' => 'public-key', 'devices' => 1]);
    $this->actingAs($none)
        ->postJson('/capabilities/push_show')
        ->assertOk()
        ->assertJsonPath('data.devices', 0);
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
