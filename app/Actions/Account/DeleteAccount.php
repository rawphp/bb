<?php

namespace App\Actions\Account;

use App\Models\User;

/**
 * ACC-005: deletes the account and all its data. Every user table's user_id cascades, and `User` deletes first the
 * rows a cascade cannot reach in order, so the account and everything in it go together or not at all.
 */
final class DeleteAccount
{
    public function __invoke(User $user): void
    {
        $user->delete();
    }
}
