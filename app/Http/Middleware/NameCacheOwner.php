<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tells the PWA whose read cache to open (OFF-003). Every response names the
 * signed-in user in a cookie the app can read, opaquely, and removes it once no
 * one is signed in. The sign-in response itself switches it, so the next user on
 * a device never opens the last user's cache, and an expired session offline
 * still opens its own user's.
 */
final class NameCacheOwner
{
    public const COOKIE = 'bb_cache_owner';

    // Browsers cap a cookie's lifetime at 400 days; any signed-in response renews it.
    private const DAYS = 400;

    public static function owner(User $user): string
    {
        $key = config('app.key');

        return substr(hash_hmac('sha256', 'read-cache-owner:'.$user->getKey(), is_string($key) ? $key : ''), 0, 16);
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Read before $next: EncryptCookies, further in, can't decrypt this plain
        // cookie and sets it to null on the same request.
        $sent = $request->cookies->get(self::COOKIE);
        $response = $next($request);

        $user = $request->user();
        $owner = $user instanceof User ? self::owner($user) : null;

        if ($owner !== $sent) {
            $response->headers->setCookie($owner === null
                ? Cookie::create(self::COOKIE)->withExpires(1)->withHttpOnly(false)
                : Cookie::create(self::COOKIE, $owner, now()->addDays(self::DAYS))
                    ->withHttpOnly(false)
                    ->withSecure((bool) config('session.secure'))
                    ->withSameSite('lax'));
        }

        return $response;
    }
}
