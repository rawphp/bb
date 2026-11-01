<?php

use App\Capabilities\Support\ErrorWording;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\Capabilities\Support\CapabilityResult;
use Tests\Support\Fixtures\Rail\TallyAddCapability;

it('CAP-014 turns an idempotency key reused with different input into a 422 that names the key', function () {
    $reused = CapabilityResult::failure(
        code: 'conflict',
        message: 'Idempotency key reused with a different request body (D-005).',
        extra: ['http_status' => 409],
    );

    $result = ErrorWording::apply($reused);

    expect($result->errorCode())->toBe('validation_failed')
        ->and($result->error['http_status'])->toBe(422)
        ->and($result->error['message'])->toBe('This request was already sent with different details.')
        ->and($result->error['violations'])->toBe([
            ['field' => 'idempotency_key', 'message' => 'Use a new key for a different request.'],
        ]);
});

it('CAP-012 words a key that is still processing plainly and keeps it retryable', function () {
    $busy = CapabilityResult::failure(
        code: 'conflict',
        message: 'Idempotency key is already processing (D-005).',
        extra: ['retryable' => true, 'http_status' => 409],
    );

    $result = ErrorWording::apply($busy);

    expect($result->errorCode())->toBe('conflict')
        ->and($result->error['message'])->toBe('This change is still being saved. Try again in a moment.')
        ->and($result->error['retryable'])->toBeTrue()
        ->and($result->error['http_status'])->toBe(409);
});

it('CAP-012 rewrites the package\'s schema errors in plain words and keeps the field names', function () {
    $registry = new CapabilityRegistry;
    (new TallyAddCapability)->definition()->register($registry);

    $result = ErrorWording::apply($registry->invoke('tally_add', ['amount' => 'lots', 'colour' => 'red'], ['actor' => tap(new User, fn (User $u) => $u->id = 1)]));

    expect($result->errorCode())->toBe('validation_failed')
        ->and($result->error['message'])->toBe('Some details need fixing.')
        ->and($result->error['violations'])->toEqualCanonicalizing([
            ['field' => 'amount', 'message' => 'This value is the wrong type.'],
            ['field' => 'colour', 'message' => 'This field is not accepted.'],
        ]);
});

it('CAP-012 maps every package violation phrase to plain words', function (string $package, string $plain) {
    $result = ErrorWording::apply(CapabilityResult::failure('validation_failed', 'JSON Schema validation failed.', [
        'violations' => [['field' => 'reps', 'message' => $package]],
    ]));

    expect($result->error['violations'])->toBe([['field' => 'reps', 'message' => $plain]]);
})->with([
    ['required property missing', 'This field is required.'],
    ['additional property not allowed', 'This field is not accepted.'],
    ['expected type integer', 'This value is the wrong type.'],
    ['value not in enum', 'Choose one of the allowed values.'],
    ['invalid uuid format', 'This value is not in the right format.'],
    ['below minimum', 'This value is too small.'],
    ['above maximum', 'This value is too large.'],
    ['shorter than minLength', 'This is too short.'],
    ['longer than maxLength', 'This is too long.'],
    ['fewer items than minItems', 'Add more items.'],
    ['more items than maxItems', 'Remove some items.'],
]);

it('CAP-012 keeps messages the server wrote itself', function () {
    $laravelRule = CapabilityResult::failure('validation_failed', 'Server-only validation failed.', [
        'violations' => [['field' => 'date', 'message' => 'The date field must be a valid date.']],
    ]);
    $domain = CapabilityResult::failure('domain_error', 'You have already finished this workout.');
    $conflict = CapabilityResult::failure('conflict', 'Undo is not possible: a later set depends on this one.');

    expect(ErrorWording::apply($laravelRule)->error['message'])->toBe('Some details need fixing.')
        ->and(ErrorWording::apply($laravelRule)->error['violations'][0]['message'])->toBe('The date field must be a valid date.')
        ->and(ErrorWording::apply($domain))->toBe($domain)
        ->and(ErrorWording::apply($conflict))->toBe($conflict);
});

it('CAP-012 words the package\'s own refusals plainly', function (string $code, string $plain) {
    $result = ErrorWording::apply(CapabilityResult::failure($code, 'Not authorized to invoke "tally_add".'));

    expect($result->errorCode())->toBe($code)
        ->and($result->error['message'])->toBe($plain);
})->with([
    ['forbidden', 'You can\'t do that.'],
    ['capability_not_in_profile', 'You can\'t do that.'],
    ['not_found', 'We couldn\'t find that.'],
    ['internal', 'Something went wrong. Try again later.'],
    ['output_invalid', 'Something went wrong. Try again later.'],
    ['audit_failed', 'Something went wrong. Try again later.'],
    ['not_configured', 'Something went wrong. Try again later.'],
    ['not_runnable', 'Something went wrong. Try again later.'],
]);

it('CAP-012 leaves successes and their meta alone', function () {
    $ok = CapabilityResult::ok(['invalidates' => []], ['idempotent_replay' => true]);

    expect(ErrorWording::apply($ok))->toBe($ok);
});
