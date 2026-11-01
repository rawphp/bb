<?php

use App\Domain\Account\SignInCodeCheck;
use App\Domain\Account\SignInCodeOutcome;
use Carbon\CarbonImmutable;

function checkCode(string $entered, int $failedTries = 0, int $minutesOld = 0): SignInCodeOutcome
{
    $issuedAt = CarbonImmutable::parse('2026-11-01 09:00:00');

    return SignInCodeCheck::check(
        matches: $entered === '123456',
        failedTries: $failedTries,
        issuedAt: $issuedAt,
        now: $issuedAt->addMinutes($minutesOld),
    );
}

it('ACC-001 accepts the right code inside ten minutes', function () {
    expect(checkCode('123456', minutesOld: 9))->toBe(SignInCodeOutcome::Accepted);
});

it('ACC-001 expires a code after ten minutes, even the right one', function () {
    expect(checkCode('123456', minutesOld: 10))->toBe(SignInCodeOutcome::Expired);
});

it('ACC-001 counts a wrong code as a try and keeps the code while tries remain', function () {
    expect(checkCode('000000', failedTries: 3))->toBe(SignInCodeOutcome::Wrong);
});

it('ACC-001 uses the code up on the fifth wrong try', function () {
    expect(checkCode('000000', failedTries: 4))->toBe(SignInCodeOutcome::UsedUp);
});

it('ACC-001 refuses even the right code after five wrong tries', function () {
    expect(checkCode('123456', failedTries: 5))->toBe(SignInCodeOutcome::UsedUp);
});

it('ACC-001 makes six-digit codes, keeping leading zeros', function () {
    foreach (range(1, 200) as $i) {
        expect(SignInCodeCheck::newCode())->toMatch('/^\d{6}$/');
    }
});

it('ACC-001 sends a new code only a minute after the last one', function () {
    $issuedAt = CarbonImmutable::parse('2026-11-01 09:00:00');

    expect(SignInCodeCheck::canResend($issuedAt, $issuedAt->addSeconds(59)))->toBeFalse()
        ->and(SignInCodeCheck::canResend($issuedAt, $issuedAt->addSeconds(60)))->toBeTrue();
});
