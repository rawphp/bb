<?php

use App\Http\Middleware\AcceptOccurredAt;
use App\Support\OccurredAt;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

beforeEach(fn () => Carbon::setTestNow('2026-11-02T10:00:00Z'));
afterEach(fn () => Carbon::setTestNow());

function outboxRequest(?string $occurredAt): Request
{
    $request = Request::create('/capabilities/tally_add', 'POST');
    if ($occurredAt !== null) {
        $request->headers->set(OccurredAt::HEADER, $occurredAt);
    }

    return $request;
}

/** Runs the middleware; returns its response and the time the next handler saw as occurred_at, if it ran. */
function acceptOccurredAt(Request $request): array
{
    app()->instance('request', $request);
    $seen = null;
    $response = (new AcceptOccurredAt)->handle($request, function () use (&$seen): Response {
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

it('OFF-007 judges the window against the walked clock, so a walked device can sync', function () {
    // UseRequestClock runs first and has already moved the clock (SET-011).
    Carbon::setTestNow('2026-11-20T10:00:00Z');

    [, $seen] = acceptOccurredAt(outboxRequest('2026-11-20T09:00:00Z'));

    expect($seen)->toBe('2026-11-20T09:00:00Z');
});

it('OFF-007 answers now outside a request that carried occurred_at', function () {
    app()->instance('request', Request::create('/'));

    expect(OccurredAt::get()->equalTo(CarbonImmutable::now()))->toBeTrue();
});
