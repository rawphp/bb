<?php

namespace App\Actions\Account;

use App\Models\User;

final class RegisterUser
{
    public function __invoke(string $name, string $email, string $password): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);
    }
}
