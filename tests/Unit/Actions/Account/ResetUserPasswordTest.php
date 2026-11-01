<?php

use App\Actions\Account\ResetUserPassword;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

// The broker keeps its tokens in the database, so the action runs on it.
uses(RefreshDatabase::class);

function resetWith(string $token): void
{
    (new ResetUserPassword)([
        'token' => $token,
        'email' => 'pat@example.com',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ]);
}

it('ACC-004 sets the new password and a new remember token', function () {
    $user = User::factory()->create(['email' => 'pat@example.com']);
    $user->forceFill(['remember_token' => 'old-token'])->save();

    resetWith(Password::createToken($user));

    $user->refresh();
    expect(Hash::check('new-password-123', $user->password))->toBeTrue()
        ->and($user->remember_token)->not->toBeNull()->not->toBe('old-token');
});

it('ACC-004 refuses a bad token on the email field and changes nothing', function () {
    $user = User::factory()->create(['email' => 'pat@example.com', 'password' => 'old-password-123']);

    expect(fn () => resetWith('wrong'))->toThrow(ValidationException::class, 'That reset link is invalid or has expired.');
    expect(Hash::check('old-password-123', $user->fresh()->password))->toBeTrue();
});
