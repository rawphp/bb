<?php

namespace App\Actions\Account;

use App\Domain\Account\SignInCodeCheck;
use App\Models\User;
use App\Notifications\SignInCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Throwable;

/** Runs on the queue (App\Jobs\EmailSignInCode), so the request answers the same for every email. */
final class SendSignInCode
{
    /**
     * Sends nothing to an email with no account. A new code replaces the old one,
     * but not within a minute of it: that one is still in the inbox, and it keeps
     * strangers from flooding the inbox without blocking the owner.
     */
    public function __invoke(string $email): void
    {
        $user = User::where('email', $email)->first();
        if ($user === null) {
            return;
        }

        $last = $user->pendingSignInCode()->value('issued_at');
        if ($last !== null && ! SignInCodeCheck::canResend(CarbonImmutable::parse($last), CarbonImmutable::now())) {
            return;
        }

        $code = SignInCodeCheck::newCode();
        $user->pendingSignInCode()->updateOrCreate(
            [],
            ['code_hash' => Hash::make($code), 'failed_tries' => 0, 'issued_at' => now()],
        );

        try {
            $user->notify(new SignInCode($code));
        } catch (Throwable $failed) {
            // Forget the code, or the one-a-minute rule would swallow the job's retry.
            $user->pendingSignInCode()->delete();

            throw $failed;
        }
    }
}
