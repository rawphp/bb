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

it('OFF-003 removes the cache owner once the session is gone, through the whole stack', function (string $method, string $uri) {
    // JSON test requests carry cookies only with credentials, as the PWA's fetch does (same-origin).
    $this->withCredentials()
        ->withUnencryptedCookie(NameCacheOwner::COOKIE, '0123456789abcdef')
        ->json($method, $uri)
        ->assertCookieExpired(NameCacheOwner::COOKIE);
})->with([
    'the app shell' => ['GET', '/'],
    'a refused capability call' => ['POST', '/capabilities/account_show'],
]);

it('OFF-003 does not send the cache owner again while it is unchanged', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withCredentials()
        ->withUnencryptedCookie(NameCacheOwner::COOKIE, NameCacheOwner::owner($user))
        ->postJson('/capabilities/account_show')
        ->assertOk();

    expect(collect($response->headers->getCookies())->map->getName())->not->toContain(NameCacheOwner::COOKIE);
});
