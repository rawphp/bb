<?php

use App\Http\Middleware\NameCacheOwner;
use App\Models\User;

beforeEach(function () {
    // The PWA is served from APP_URL, so Sanctum treats its requests as stateful.
    $this->withHeader('Referer', config('app.url'));
});

it('OFF-003 names the new cache owner on the sign-in response, unencrypted so the app can read it', function () {
    $user = User::factory()->create(['email' => 'sam@example.com', 'password' => 'correct-horse-battery']);

    $this->postJson('/sign-in', ['email' => 'sam@example.com', 'password' => 'correct-horse-battery'])
        ->assertNoContent()
        ->assertPlainCookie(NameCacheOwner::COOKIE, NameCacheOwner::owner($user));
});

it('OFF-003 names the cache owner on a capability call by a signed-in user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/capabilities/account_show')
        ->assertOk()
        ->assertPlainCookie(NameCacheOwner::COOKIE, NameCacheOwner::owner($user));
});
