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

it('ACC-002 starts a new session and CSRF token on sign-in and on sign-up', function (string $uri, array $body) {
    User::factory()->create(['email' => 'sam@example.com', 'password' => 'correct-horse-battery']);
    $this->startSession();
    $id = session()->getId();
    $token = session()->token();

    $this->postJson($uri, $body)->assertNoContent();

    // SessionGuard::login() regenerates the session too, so this stays green
    // if the controllers' regenerate() goes; it fails only with both gone.
    expect(session()->getId())->not->toBe($id)
        ->and(session()->token())->not->toBe($token);
})->with([
    'sign-in' => ['/sign-in', ['email' => 'sam@example.com', 'password' => 'correct-horse-battery']],
    'sign-up' => ['/register', [
        'name' => 'Alex Lifter',
        'email' => 'alex@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ]],
]);

it('ACC-002 refuses an eleventh sign-in attempt within a minute', function () {
    User::factory()->create(['email' => 'sam@example.com', 'password' => 'correct-horse-battery']);

    foreach (range(1, 10) as $attempt) {
        $this->postJson('/sign-in', ['email' => 'sam@example.com', 'password' => 'wrong'])->assertUnprocessable();
    }

    $this->postJson('/sign-in', ['email' => 'sam@example.com', 'password' => 'correct-horse-battery'])
        ->assertTooManyRequests();
    $this->assertGuest();
});

it('ACC-002 does not sign up a second account while signed in', function () {
    $this->actingAs(User::factory()->create());

    $this->postJson('/register', [
        'name' => 'Alex Lifter',
        'email' => 'alex@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertRedirect();

    expect(User::where('email', 'alex@example.com')->exists())->toBeFalse();
});

it('ACC-002 refuses a sign-up with invalid details and creates no account', function (array $override, string $field) {
    $this->postJson('/register', array_merge([
        'name' => 'Alex Lifter',
        'email' => 'alex@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ], $override))->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(User::count())->toBe(0);
})->with([
    'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'confirmation does not match' => [['password_confirmation' => 'something-else-entirely'], 'password'],
    'uppercase email' => [['email' => 'Alex@Example.com'], 'email'],
    'no name' => [['name' => ''], 'name'],
]);
