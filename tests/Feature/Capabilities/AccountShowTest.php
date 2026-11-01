<?php

use App\Models\User;

it('CAP-002 account_show returns the signed-in user\'s name and email', function () {
    $user = User::factory()->create(['name' => 'Sam Lifter', 'email' => 'sam@example.com']);

    $this->actingAs($user)
        ->postJson('/capabilities/account_show')
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data', ['name' => 'Sam Lifter', 'email' => 'sam@example.com']);
});

it('CAP-004 refuses account_show over HTTP when signed out', function () {
    $this->postJson('/capabilities/account_show')->assertUnauthorized();
});
