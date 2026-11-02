<?php

use App\Http\Middleware\NameCacheOwner;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

function userWithId(int $id): User
{
    return tap(new User, fn (User $user) => $user->id = $id);
}

/** Runs the middleware for a request made with $cookie and answered for $user, and returns the owner cookie it set, if any. */
function ownerCookieAfter(?string $cookie, ?User $user): ?Cookie
{
    $request = Request::create('/capabilities/account_show', 'POST', cookies: $cookie === null ? [] : [NameCacheOwner::COOKIE => $cookie]);
    $request->setUserResolver(fn () => $user);
    $response = (new NameCacheOwner)->handle($request, fn () => new Response);

    return collect($response->headers->getCookies())->first(fn (Cookie $c) => $c->getName() === NameCacheOwner::COOKIE);
}

it('OFF-003 names the signed-in user as the read cache owner, in a cookie the app can read', function () {
    $cookie = ownerCookieAfter(null, userWithId(1));

    expect($cookie->getValue())->toBe(NameCacheOwner::owner(userWithId(1)))
        ->and($cookie->isHttpOnly())->toBeFalse()
        ->and($cookie->getPath())->toBe('/')
        ->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(399)->getTimestamp());
});

it('OFF-003 gives each user a different owner that does not show the user id', function () {
    expect(NameCacheOwner::owner(userWithId(1)))->not->toBe(NameCacheOwner::owner(userWithId(2)))
        ->and(NameCacheOwner::owner(userWithId(1)))->toMatch('/^[0-9a-f]{16}$/')
        ->and(NameCacheOwner::owner(userWithId(1)))->toBe(NameCacheOwner::owner(userWithId(1)));
});

it('OFF-003 switches the owner when another user signs in', function () {
    $cookie = ownerCookieAfter(NameCacheOwner::owner(userWithId(1)), userWithId(2));

    expect($cookie->getValue())->toBe(NameCacheOwner::owner(userWithId(2)));
});

it('OFF-003 removes the owner once no one is signed in', function () {
    expect(ownerCookieAfter(NameCacheOwner::owner(userWithId(1)), null)->isCleared())->toBeTrue();
});

it('OFF-003 leaves the cookie alone when the owner has not changed', function (?callable $owner, ?User $user) {
    expect(ownerCookieAfter($owner === null ? null : $owner(), $user))->toBeNull();
})->with([
    'signed in as the same user' => [fn () => NameCacheOwner::owner(userWithId(1)), userWithId(1)],
    'signed out with no owner' => [null, null],
]);
