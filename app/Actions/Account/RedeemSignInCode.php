<?php

namespace App\Actions\Account;

use App\Domain\Account\SignInCodeCheck;
use App\Domain\Account\SignInCodeOutcome;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class RedeemSignInCode
{
    /**
     * One answer for every refusal (wrong, used up, expired, never sent, no account),
     * so the answer never shows whether an email has an account.
     */
    public const REFUSED = 'That code is wrong or no longer works. Ask for a new code if you need one.';

    public const TOO_MANY = 'Too many wrong codes for this email. Sign in with your password, or try again later.';

    /** Returns the user the code belongs to, or throws on the code field. */
    public function __invoke(string $email, string $code, string $ip): User
    {
        $outcome = DB::transaction(function () use ($email, $code, $ip): User|string {
            $user = User::where('email', $email)->first();
            // The lock makes parallel guesses take turns, so a code never gets more than
            // five and the address never gets more than its caps.
            $pending = $user?->pendingSignInCode()->lockForUpdate()->first();

            // Wrong codes are counted per address and IP, and per address, account or not,
            // and only on a refusal, so the owner's right code never uses one up.
            $windows = collect(SignInCodeCheck::WRONG_CODES_PER_ADDRESS_AND_IP)
                ->mapWithKeys(fn (int $max, int $seconds) => ["sign-in-code-wrong:{$seconds}|{$email}|{$ip}" => [$seconds, $max]])
                ->merge(collect(SignInCodeCheck::WRONG_CODES_PER_ADDRESS)
                    ->mapWithKeys(fn (int $max, int $seconds) => ["sign-in-code-wrong:{$seconds}|{$email}" => [$seconds, $max]]));
            if ($windows->contains(fn (array $window, string $key) => RateLimiter::tooManyAttempts($key, $window[1]))) {
                return self::TOO_MANY;
            }

            if ($user === null || $pending === null) {
                // A real check costs one bcrypt; spend the same here so the answer time shows nothing.
                Hash::make($code);
                $result = SignInCodeOutcome::Expired;
            } else {
                $result = SignInCodeCheck::check(
                    matches: Hash::check($code, $pending->code_hash),
                    failedTries: $pending->failed_tries,
                    issuedAt: $pending->issued_at,
                    now: CarbonImmutable::now(),
                );
                $result === SignInCodeOutcome::Wrong ? $pending->increment('failed_tries') : $pending->delete();
            }

            if ($user !== null && $result === SignInCodeOutcome::Accepted) {
                return $user;
            }

            $windows->each(fn (array $window, string $key) => RateLimiter::hit($key, $window[0]));

            return self::REFUSED;
        });

        return $outcome instanceof User ? $outcome : throw ValidationException::withMessages(['code' => $outcome]);
    }
}
