<?php

use App\Domain\Tokens\TokenScope;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Str;

it('AGT-041 serves the capabilities CLI with a personal access token: catalog, describe and invoke', function () {
    $this->getJson('/capabilities')->assertUnauthorized();
    $user = User::factory()->create(['name' => 'Sam Lifter']);
    $secret = Str::random(40);
    $token = PersonalAccessToken::factory()->for($user, 'tokenable')
        ->create(['scope' => TokenScope::Everything, 'abilities' => ['*'], 'token' => hash('sha256', $secret)]);
    $cli = $this->withHeader('Authorization', "Bearer {$token->id}|{$secret}");

    expect($cli->getJson('/capabilities')->assertOk()->content())->toContain('account_show');
    $cli->getJson('/capabilities/account_show')->assertOk();
    $cli->postJson('/capabilities/account_show')->assertOk();
});
