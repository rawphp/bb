<?php

namespace App\Actions\Account;

use App\Domain\Account\SignInCodeCheck;
use App\Models\PendingSignInCode;
use App\Models\User;
use App\Notifications\SignInCode;
use Illuminate\Support\Facades\Hash;

final class SendSignInCode
{
    /** Sends nothing to an email with no account, so the answer never reveals which emails have one. */
    public function __invoke(string $email): void
    {
        $user = User::where('email', $email)->first();
        if ($user === null) {
            return;
        }

        $code = SignInCodeCheck::newCode();
        PendingSignInCode::updateOrCreate(
            ['user_id' => $user->id],
            ['code_hash' => Hash::make($code), 'failed_tries' => 0, 'issued_at' => now()],
        );

        $user->notify(new SignInCode($code));
    }
}
