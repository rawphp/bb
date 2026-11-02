<?php

namespace App\Actions\Account;

use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ResetUserPassword
{
    /** @param  array{token: string, email: string, password: string, password_confirmation: string}  $credentials */
    public function __invoke(array $credentials): void
    {
        $status = Password::reset($credentials, function (User $user, string $password): void {
            // A new remember_token ends every "remember me" cookie issued before the reset.
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'That reset link is invalid or has expired. Ask for a new one.']);
        }
    }
}
