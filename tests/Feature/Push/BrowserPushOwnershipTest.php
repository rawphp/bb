<?php

use App\Models\PushSubscription;
use App\Models\User;

// A shared browser must not keep showing an account's notifications once that
// account has left it, even when the app never calls push_unsubscribe (NOTIF-010).
// usePush() names the browser's endpoint in a cookie; the host routes read it.

beforeEach(function () {
    // postJson sends cookies only with credentials.
    $this->withHeader('Referer', config('app.url'))->withCredentials();
});

it('NOTIF-010 signing out removes this browser\'s subscription and keeps the user\'s other browsers', function () {
    $user = User::factory()->create();
    $browser = PushSubscription::factory()->for($user)->create();
    $phone = PushSubscription::factory()->for($user)->create();

    $this->actingAs($user)
        ->withUnencryptedCookie(PushSubscription::BROWSER_COOKIE, $browser->endpoint)
        ->postJson('/sign-out')
        ->assertNoContent()
        ->assertCookieExpired(PushSubscription::BROWSER_COOKIE);

    expect(PushSubscription::query()->pluck('id')->all())->toBe([$phone->id]);
});

it('NOTIF-010 signing out never removes another user\'s subscription named in the cookie', function () {
    [$user, $other] = User::factory()->count(2)->create();
    $theirs = PushSubscription::factory()->for($other)->create();

    $this->actingAs($user)
        ->withUnencryptedCookie(PushSubscription::BROWSER_COOKIE, $theirs->endpoint)
        ->postJson('/sign-out')
        ->assertNoContent();

    expect(PushSubscription::query()->pluck('id')->all())->toBe([$theirs->id]);
});

it('NOTIF-010 signing in on a browser stops the previous account\'s notifications there', function () {
    $previous = User::factory()->create();
    User::factory()->create(['email' => 'sam@example.com', 'password' => 'correct-horse-battery']);
    $browser = PushSubscription::factory()->for($previous)->create();
    $phone = PushSubscription::factory()->for($previous)->create();

    $this->withUnencryptedCookie(PushSubscription::BROWSER_COOKIE, $browser->endpoint)
        ->postJson('/sign-in', ['email' => 'sam@example.com', 'password' => 'correct-horse-battery'])
        ->assertNoContent();

    expect(PushSubscription::query()->pluck('id')->all())->toBe([$phone->id]);
});
