<?php

use App\Http\Middleware\AcceptOccurredAt;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\NameCacheOwner;
use App\Http\Middleware\UseRequestClock;
use Illuminate\Cookie\Middleware\EncryptCookies as BaseEncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA cookie auth for the PWA on the same domain.
        $middleware->statefulApi();
        // Whose read cache the PWA opens (OFF-003): set on sign-in, cleared on sign-out.
        // Outside cookie encryption (Sanctum's, in api), so its cookie stays readable.
        $middleware->prependToGroup('web', NameCacheOwner::class);
        $middleware->prependToGroup('api', NameCacheOwner::class);
        // First, so everything after it runs at X-BB-Now in local and testing (SET-011).
        $middleware->prependToGroup('api', UseRequestClock::class);
        // After the clock: a replayed write runs at the moment the user acted (OFF-007).
        $middleware->appendToGroup('api', AcceptOccurredAt::class);
        // Sign-in and sign-out read the push endpoint cookie the app writes (NOTIF-010).
        $middleware->web(replace: [BaseEncryptCookies::class => EncryptCookies::class]);
        // There is no login page: the PWA shows sign-in itself, so a signed-out
        // request gets a 401, never a redirect.
        $middleware->redirectGuestsTo(null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Capability calls always answer in JSON, whatever the Accept header.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', config('capabilities.surfaces.http.prefix').'/*')
                || $request->expectsJson(),
        );
    })->create();
