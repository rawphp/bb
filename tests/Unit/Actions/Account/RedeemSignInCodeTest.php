<?php

use App\Actions\Account\RedeemSignInCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

// The action's job is what it keeps and removes in pending_sign_in_codes, so it runs on the database.
uses(RefreshDatabase::class);

function userWithCode(string $code = '123456', int $failedTries = 0, int $minutesOld = 0): User
{
    $user = User::factory()->create(['email' => 'sam@example.com']);
    $user->pendingSignInCode()->create([
        'code_hash' => Hash::make($code),
        'failed_tries' => $failedTries,
        'issued_at' => now()->subMinutes($minutesOld),
    ]);

    return $user;
}

function redeem(string $code, string $email = 'sam@example.com'): ?User
{
    try {
        return (new RedeemSignInCode)($email, $code, '10.0.0.1');
    } catch (ValidationException $refused) {
        expect($refused->errors())->toBe(['code' => [RedeemSignInCode::REFUSED]]);

        return null;
    }
}

it('ACC-001 returns the user and removes the code when it is right', function () {
    $user = userWithCode();

    expect(redeem('123456')?->is($user))->toBeTrue()
        ->and($user->pendingSignInCode()->exists())->toBeFalse();
});

it('ACC-001 counts a wrong code and keeps it while tries remain', function () {
    $user = userWithCode(failedTries: 3);

    expect(redeem('000000'))->toBeNull()
        ->and($user->pendingSignInCode()->value('failed_tries'))->toBe(4);
});

it('ACC-001 removes the code on the fifth wrong try', function () {
    $user = userWithCode(failedTries: 4);

    expect(redeem('000000'))->toBeNull()
        ->and($user->pendingSignInCode()->exists())->toBeFalse();
});

it('ACC-001 removes an expired code, even when it is right', function () {
    $user = userWithCode(minutesOld: 10);

    expect(redeem('123456'))->toBeNull()
        ->and($user->pendingSignInCode()->exists())->toBeFalse();
});

it('ACC-001 refuses an email with no account or no code the same way', function () {
    User::factory()->create(['email' => 'pat@example.com']);

    expect(redeem('123456', 'nobody@example.com'))->toBeNull()
        ->and(redeem('123456', 'pat@example.com'))->toBeNull();
});
