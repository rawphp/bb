<?php

namespace App\Actions\Account;

use App\Domain\Account\SignInCodeCheck;
use App\Domain\Account\SignInCodeOutcome;
use App\Models\PendingSignInCode;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class RedeemSignInCode
{
    /** Returns the user the code belongs to, or throws with the reason on the code field. */
    public function __invoke(string $email, string $code): User
    {
        [$outcome, $user] = DB::transaction(function () use ($email, $code): array {
            $user = User::where('email', $email)->first();
            $pending = $user === null ? null : PendingSignInCode::where('user_id', $user->id)->lockForUpdate()->first();
            if ($user === null || $pending === null) {
                return [SignInCodeOutcome::Expired, null];
            }

            $outcome = SignInCodeCheck::check(
                matches: Hash::check($code, $pending->code_hash),
                failedTries: $pending->failed_tries,
                issuedAt: $pending->issued_at,
                now: CarbonImmutable::now(),
            );

            $outcome === SignInCodeOutcome::Wrong ? $pending->increment('failed_tries') : $pending->delete();

            return [$outcome, $user];
        });

        return match ($outcome) {
            SignInCodeOutcome::Accepted => $user,
            SignInCodeOutcome::Wrong => throw ValidationException::withMessages(['code' => 'That code is wrong.']),
            SignInCodeOutcome::UsedUp => throw ValidationException::withMessages(['code' => 'Too many wrong tries. Ask for a new code.']),
            SignInCodeOutcome::Expired => throw ValidationException::withMessages(['code' => 'That code has expired. Ask for a new one.']),
        };
    }
}
