<?php

namespace App\Actions\Tokens;

use App\Models\PersonalAccessToken;
use App\Models\User;

/** Revokes one of the user's personal access tokens (CAP-021). */
final class RevokeToken
{
    public function __invoke(User $user, string $id): void
    {
        PersonalAccessToken::ownedBy($user)->whereKey($id)->firstOrFail()->delete();
    }
}
