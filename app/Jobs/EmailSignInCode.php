<?php

namespace App\Jobs;

use App\Actions\Account\SendSignInCode;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Makes and mails a sign-in code off the request: the answer then takes the same time,
 * and a mail failure looks the same, whether or not the email has an account.
 */
final class EmailSignInCode implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public readonly string $email) {}

    public function handle(SendSignInCode $send): void
    {
        $send($this->email);
    }
}
