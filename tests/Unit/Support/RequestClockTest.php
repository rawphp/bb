<?php

use App\Http\Middleware\UseRequestClock;
use App\Support\RequestClock;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

afterEach(fn () => Carbon::setTestNow());

function walkedRequest(?string $now): Request
{
    $request = Request::create('/capabilities/account_show', 'POST');
    if ($now !== null) {
        $request->headers->set('X-BB-Now', $now);
    }

    return $request;
}

/** Runs the middleware and returns the time the next handler saw. */
function nowInsideRequest(Request $request): string
{
    $seen = '';
    (new UseRequestClock)->handle($request, function () use (&$seen): Response {
        $seen = Carbon::now()->toIso8601ZuluString();

        return new Response;
    });

    return $seen;
}

it('SET-011 runs a request at X-BB-Now in local and testing', function (string $env) {
    app()->detectEnvironment(fn () => $env);

    expect(nowInsideRequest(walkedRequest('2031-03-04T05:06:07.000Z')))->toBe('2031-03-04T05:06:07Z');
})->with(['local', 'testing']);

it('SET-011 ignores X-BB-Now in every other environment', function (string $env) {
    app()->detectEnvironment(fn () => $env);
    Carbon::setTestNow('2026-11-01 09:00:00');

    expect(nowInsideRequest(walkedRequest('2031-03-04T05:06:07.000Z')))->toBe('2026-11-01T09:00:00Z');
})->with(['production', 'staging']);

it('SET-011 ignores an X-BB-Now in the past', function () {
    Carbon::setTestNow('2026-11-01 09:00:00');

    expect(nowInsideRequest(walkedRequest('2026-10-25T09:00:00Z')))->toBe('2026-11-01T09:00:00Z');
});

it('SET-011 ignores a missing or unreadable X-BB-Now', function (?string $header) {
    Carbon::setTestNow('2026-11-01 09:00:00');

    expect(nowInsideRequest(walkedRequest($header)))->toBe('2026-11-01T09:00:00Z');
})->with([null, '', 'not a date']);

it('SET-011 puts the clock back after the request, even when it throws', function () {
    Carbon::setTestNow('2026-11-01 09:00:00');

    expect(fn () => (new UseRequestClock)->handle(
        walkedRequest('2031-03-04T05:06:07Z'),
        fn () => throw new RuntimeException('boom'),
    ))->toThrow(RuntimeException::class);

    expect(Carbon::now()->toIso8601ZuluString())->toBe('2026-11-01T09:00:00Z');
});

it('SET-011 restores a real clock when none was fixed before', function () {
    Carbon::setTestNow();

    nowInsideRequest(walkedRequest('2031-03-04T05:06:07Z'));

    expect(Carbon::hasTestNow())->toBeFalse();
});

it('SET-011 parses only where the header is honoured', function () {
    expect(RequestClock::parse('2031-03-04T05:06:07+10:00')?->toIso8601ZuluString())->toBe('2031-03-03T19:06:07Z');

    app()->detectEnvironment(fn () => 'production');

    expect(RequestClock::parse('2031-03-04T05:06:07+10:00'))->toBeNull();
});
