<?php

use App\Models\User;

beforeEach(function () {
    // The PWA is served from APP_URL, so Sanctum treats its requests as stateful.
    $this->withHeader('Referer', config('app.url'));
});

it('ACC-002 signs up with email and password into one account the capabilities see', function () {
    $this->postJson('/register', [
        'name' => 'Sam Lifter',
        'email' => 'sam@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertNoContent();

    expect(User::where('email', 'sam@example.com')->count())->toBe(1);

    $this->postJson('/capabilities/account_show')
        ->assertOk()
        ->assertJsonPath('data.email', 'sam@example.com');
});

it('ACC-002 signs in with email and password and keeps the session cookie', function () {
    User::factory()->create(['email' => 'sam@example.com', 'password' => 'correct-horse-battery']);

    $this->postJson('/sign-in', ['email' => 'sam@example.com', 'password' => 'correct-horse-battery'])
        ->assertNoContent();

    $this->postJson('/capabilities/account_show')
        ->assertOk()
        ->assertJsonPath('data.email', 'sam@example.com');
});

it('ACC-002 refuses a wrong password with a message on the email field', function () {
    User::factory()->create(['email' => 'sam@example.com', 'password' => 'correct-horse-battery']);

    $this->postJson('/sign-in', ['email' => 'sam@example.com', 'password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => 'That email and password do not match an account.']);

    $this->assertGuest();
});

it('ACC-002 refuses a second account with the same email', function () {
    User::factory()->create(['email' => 'sam@example.com']);

    $this->postJson('/register', [
        'name' => 'Sam Again',
        'email' => 'sam@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
});
