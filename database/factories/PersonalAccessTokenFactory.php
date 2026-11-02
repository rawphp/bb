<?php

namespace Database\Factories;

use App\Domain\Tokens\TokenScope;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PersonalAccessToken> */
class PersonalAccessTokenFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tokenable_type' => (new User)->getMorphClass(),
            'tokenable_id' => User::factory(),
            'name' => 'Laptop MCP',
            'token' => hash('sha256', Str::random(40)),
            'abilities' => ['*'],
            'scope' => TokenScope::Everything,
            'expires_at' => now()->addDays(90),
        ];
    }
}
