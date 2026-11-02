<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Password;
use Throwable;

/**
 * Mails a reset link off the request: the answer then takes the same time,
 * and a mail failure looks the same, whether or not the email has an account.
 */
final class EmailPasswordResetLink implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public readonly string $email) {}

    public function handle(): void
    {
        // The status is ignored on purpose: nobody is waiting for it.
        Password::sendResetLink(['email' => $this->email], function (User $user, string $token): void {
            try {
                $user->sendPasswordResetNotification($token);
            } catch (Throwable $failed) {
                // Forget the token, or the broker's one-link-a-minute throttle would swallow the retry.
                Password::deleteToken($user);

                throw $failed;
            }
        });
    }
}
