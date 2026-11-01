<?php

use App\Models\User;

beforeEach(function () {
    $this->withHeader('Referer', config('app.url'));
});

it('ACC-006 signs out and the capabilities stop answering', function () {
    User::factory()->create(['email' => 'sam@example.com', 'password' => 'correct-horse-battery']);
    $this->postJson('/sign-in', ['email' => 'sam@example.com', 'password' => 'correct-horse-battery'])->assertNoContent();
    $this->startSession();
    $token = session()->token();

    $this->postJson('/sign-out')->assertNoContent();

    $this->assertGuest('web');
    expect(session()->token())->not->toBe($token);
    $this->postJson('/capabilities/account_show')->assertUnauthorized();
});

it('ACC-006 answers 401 to a sign-out with no session', function () {
    $this->postJson('/sign-out')->assertUnauthorized();
});
