<?php

use App\Models\User;
use App\Notifications\SignInCode;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->withHeader('Referer', config('app.url'));
    Notification::fake();
});

function emailedCode(User $user): string
{
    $code = null;
    Notification::assertSentTo($user, SignInCode::class, function (SignInCode $notification) use (&$code) {
        $code = $notification->code;

        return true;
    });

    return $code;
}

it('ACC-001 emails a six-digit code and signs in with it once', function () {
    $user = User::factory()->create(['email' => 'sam@example.com']);

    $this->postJson('/sign-in/code', ['email' => 'Sam@Example.com'])->assertNoContent();
    $code = emailedCode($user);
    expect($code)->toMatch('/^\d{6}$/')
        ->and((new SignInCode($code))->toMail($user)->render()->toHtml())->toContain($code);

    $this->postJson('/sign-in/code/verify', ['email' => 'sam@example.com', 'code' => $code])->assertNoContent();
    $this->assertAuthenticatedAs($user, 'web');

    $this->postJson('/sign-out')->assertNoContent();
    $this->postJson('/sign-in/code/verify', ['email' => 'sam@example.com', 'code' => $code])
        ->assertUnprocessable()->assertJsonValidationErrors(['code' => 'That code has expired. Ask for a new one.']);
});

it('ACC-001 answers an unknown email the same way and sends nothing', function () {
    $this->postJson('/sign-in/code', ['email' => 'nobody@example.com'])->assertNoContent();

    Notification::assertNothingSent();
    $this->postJson('/sign-in/code/verify', ['email' => 'nobody@example.com', 'code' => '123456'])
        ->assertUnprocessable()->assertJsonValidationErrors(['code' => 'That code has expired. Ask for a new one.']);
});

it('ACC-001 refuses a code after ten minutes', function () {
    $user = User::factory()->create(['email' => 'sam@example.com']);
    $this->postJson('/sign-in/code', ['email' => 'sam@example.com']);
    $code = emailedCode($user);

    $this->travel(10)->minutes();

    $this->postJson('/sign-in/code/verify', ['email' => 'sam@example.com', 'code' => $code])
        ->assertUnprocessable()->assertJsonValidationErrors(['code' => 'That code has expired. Ask for a new one.']);
    $this->assertGuest();
});

it('ACC-001 allows five tries, then needs a new code', function () {
    $user = User::factory()->create(['email' => 'sam@example.com']);
    $this->postJson('/sign-in/code', ['email' => 'sam@example.com']);
    $code = emailedCode($user);
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 4) as $try) {
        $this->postJson('/sign-in/code/verify', ['email' => 'sam@example.com', 'code' => $wrong])
            ->assertUnprocessable()->assertJsonValidationErrors(['code' => 'That code is wrong.']);
    }
    $this->postJson('/sign-in/code/verify', ['email' => 'sam@example.com', 'code' => $wrong])
        ->assertUnprocessable()->assertJsonValidationErrors(['code' => 'Too many wrong tries. Ask for a new code.']);
    $this->postJson('/sign-in/code/verify', ['email' => 'sam@example.com', 'code' => $code])
        ->assertUnprocessable()->assertJsonValidationErrors(['code' => 'That code has expired. Ask for a new one.']);
    $this->assertGuest();

    Notification::fake();
    $this->postJson('/sign-in/code', ['email' => 'sam@example.com']);
    $this->postJson('/sign-in/code/verify', ['email' => 'sam@example.com', 'code' => emailedCode($user)])
        ->assertNoContent();
    $this->assertAuthenticatedAs($user);
});

it('ACC-001 replaces the old code when a new one is sent', function () {
    $user = User::factory()->create(['email' => 'sam@example.com']);
    $this->postJson('/sign-in/code', ['email' => 'sam@example.com']);
    $first = emailedCode($user);

    Notification::fake();
    $this->postJson('/sign-in/code', ['email' => 'sam@example.com']);
    $second = emailedCode($user);

    if ($first !== $second) {
        $this->postJson('/sign-in/code/verify', ['email' => 'sam@example.com', 'code' => $first])
            ->assertUnprocessable();
    }
    $this->postJson('/sign-in/code/verify', ['email' => 'sam@example.com', 'code' => $second])->assertNoContent();
});

it('ACC-001 rejects a code that is not six digits', function () {
    $this->postJson('/sign-in/code/verify', ['email' => 'sam@example.com', 'code' => '12345'])
        ->assertUnprocessable()->assertJsonValidationErrors('code');
});

it('ACC-001 sends at most three codes to one email in ten minutes', function () {
    User::factory()->create(['email' => 'sam@example.com']);

    foreach (range(1, 3) as $send) {
        $this->postJson('/sign-in/code', ['email' => 'sam@example.com'])->assertNoContent();
    }
    $this->postJson('/sign-in/code', ['email' => 'SAM@example.com'])->assertTooManyRequests();

    $this->travel(10)->minutes();
    $this->postJson('/sign-in/code', ['email' => 'sam@example.com'])->assertNoContent();
});
