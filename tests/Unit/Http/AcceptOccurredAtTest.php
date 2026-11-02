<?php

use App\Capabilities\Support\IdempotencyKeys;
use App\Http\Middleware\AcceptOccurredAt;
use App\Models\User;
use App\Support\OccurredAt;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Rawphp\Capabilities\Support\FixedClock;
use Rawphp\Capabilities\Support\InMemoryIdempotencyStore;
use Symfony\Component\HttpFoundation\Response;

beforeEach(fn () => Carbon::setTestNow('2026-11-02T10:00:00Z'));
afterEach(fn () => Carbon::setTestNow());

function outboxRequest(?string $occurredAt, ?string $key = null): Request
{
    $request = Request::create('/capabilities/tally_add', 'POST');
    if ($occurredAt !== null) {
        $request->headers->set(OccurredAt::HEADER, $occurredAt);
    }
    if ($key !== null) {
        $request->headers->set('Idempotency-Key', $key);
    }
    $route = (new Route('POST', 'capabilities/{name}', fn () => null))->bind($request);
    $request->setRouteResolver(fn () => $route);

    return $request;
}

/** The idempotency store at the test's now: it answers no row past its expiry. */
function idempotencyStore(): InMemoryIdempotencyStore
{
    return new InMemoryIdempotencyStore(new FixedClock(new DateTimeImmutable('2026-11-02T10:00:00Z')));
}

/** The answer the server stored for user 7's tally_add under $key, expiring at $expires. */
function storedAnswer(string $key, string $expires): InMemoryIdempotencyStore
{
    $store = idempotencyStore();
    $store->put([
        'tenant_id' => 'default-tenant',
        'actor_type' => 'user',
        'actor_id' => '7',
        'capability_name' => 'tally_add',
        'idempotency_key' => $key,
        'request_hash' => 'h',
        'status' => 'completed',
        'created_at' => '2026-10-26T09:00:00+00:00',
        'expires_at' => $expires,
    ]);

    return $store;
}

/** Runs the middleware; returns its response and the time the next handler saw as occurred_at, if it ran. */
function acceptOccurredAt(Request $request, ?InMemoryIdempotencyStore $store = null): array
{
    app()->instance('request', $request);
    // After instance(): rebinding the request gives it the auth guard's resolver.
    $request->setUserResolver(fn () => tap(new User, fn (User $user) => $user->id = 7));
    $seen = null;
    $response = (new AcceptOccurredAt(new IdempotencyKeys($store ?? idempotencyStore())))->handle($request, function () use (&$seen): Response {
        $seen = OccurredAt::get()->toIso8601ZuluString();

        return new Response('next');
    });

    return [$response, $seen];
}

it('OFF-007 gives the next handler the time the user acted, from the outbox entry', function () {
    [$response, $seen] = acceptOccurredAt(outboxRequest('2026-11-01T07:15:00.000Z'));

    expect($response->getContent())->toBe('next')
        ->and($seen)->toBe('2026-11-01T07:15:00Z');
});

it('OFF-007 uses the request time when a write carries no occurred_at', function () {
    [, $seen] = acceptOccurredAt(outboxRequest(null));

    expect($seen)->toBe('2026-11-02T10:00:00Z');
});

it('OFF-007 accepts occurred_at up to 7 days old and up to 5 minutes ahead', function (string $at) {
    [$response] = acceptOccurredAt(outboxRequest($at));

    expect($response->getContent())->toBe('next');
})->with([
    '7 days old' => '2026-10-26T10:00:00Z',
    '5 minutes ahead' => '2026-11-02T10:05:00Z',
]);

it('OFF-007 refuses occurred_at outside the window with a message that names it', function (string $at, string $message) {
    [$response, $seen] = acceptOccurredAt(outboxRequest($at));
    $body = json_decode((string) $response->getContent(), true);

    expect($seen)->toBeNull()
        ->and($response->getStatusCode())->toBe(422)
        ->and($body['ok'])->toBeFalse()
        ->and($body['error']['code'])->toBe('validation_failed')
        ->and($body['error']['message'])->toBe($message)
        ->and($body['error']['violations'])->toBe([['field' => 'occurred_at', 'message' => $message]]);
})->with([
    'over 7 days old' => ['2026-10-26T09:59:59Z', 'This change was made more than 7 days ago, so it can\'t be saved.'],
    'over 5 minutes ahead' => ['2026-11-02T10:05:01Z', 'This change is dated in the future. Check the date and time on this device.'],
    'not a time' => ['yesterday-ish', 'The time this change was made could not be read.'],
]);

it('OFF-007 judges the window against the current clock, which UseRequestClock may have walked', function () {
    // UseRequestClock runs first and has already moved the clock (SET-011).
    Carbon::setTestNow('2026-11-20T10:00:00Z');

    [, $seen] = acceptOccurredAt(outboxRequest('2026-11-20T09:00:00Z'));

    expect($seen)->toBe('2026-11-20T09:00:00Z');
});

it('OFF-007 answers now outside a request that carried occurred_at', function () {
    app()->instance('request', Request::create('/'));

    expect(OccurredAt::get()->equalTo(CarbonImmutable::now()))->toBeTrue();
});

it('CAP-014 lets a write the server already holds under its Idempotency-Key through, however old, so it gets the stored answer', function () {
    $store = storedAnswer('tally_add:K', '2026-11-04T09:00:00+00:00');

    [$response, $seen] = acceptOccurredAt(outboxRequest('2026-10-20T09:00:00Z', 'tally_add:K'), $store);

    expect($response->getContent())->toBe('next')
        ->and($seen)->toBe('2026-10-20T09:00:00Z');
});

it('OFF-007 still refuses an old write under a key the server holds no live answer for', function (?string $expires, string $key) {
    $store = $expires === null ? idempotencyStore() : storedAnswer('tally_add:K', $expires);

    [$response] = acceptOccurredAt(outboxRequest('2026-10-20T09:00:00Z', $key), $store);

    expect($response->getStatusCode())->toBe(422);
})->with([
    'never sent' => [null, 'tally_add:K'],
    'another key' => ['2026-11-04T09:00:00+00:00', 'tally_add:other'],
    'answer expired' => ['2026-11-02T09:59:59+00:00', 'tally_add:K'],
]);

it('OFF-051 the server keeps an idempotency key for longer than the outbox may replay it', function () {
    $keptSeconds = config('capabilities.idempotency.ttl_hours') * 3600;
    $replaySeconds = OccurredAt::MAX_AGE_DAYS * 86400 + OccurredAt::MAX_AHEAD_MINUTES * 60;

    expect($keptSeconds)->toBeGreaterThan($replaySeconds);
});
