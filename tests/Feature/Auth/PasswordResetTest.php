<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->withHeader('Referer', config('app.url'));
    Notification::fake();
});

function resetToken(User $user): string
{
    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user, &$token) {
        $token = $notification->token;
        expect($notification->toMail($user)->actionUrl)
            ->toBe(config('app.url').'/reset-password?token='.$token.'&email='.urlencode($user->email));

        return true;
    });

    return $token;
}

it('ACC-004 emails a reset link that opens the app, and the new password signs in', function () {
    $user = User::factory()->create(['email' => 'pat+tag@example.com', 'password' => 'old-password-123']);

    $this->postJson('/forgot-password', ['email' => 'Pat+tag@example.com'])->assertNoContent();

    $this->postJson('/reset-password', [
        'token' => resetToken($user),
        'email' => 'pat+tag@example.com',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertNoContent();

    $this->postJson('/sign-in', ['email' => 'pat+tag@example.com', 'password' => 'old-password-123'])->assertUnprocessable();
    $this->postJson('/sign-in', ['email' => 'pat+tag@example.com', 'password' => 'new-password-123'])->assertNoContent();
});

it('ACC-004 answers an unknown email the same way and sends nothing', function () {
    $this->postJson('/forgot-password', ['email' => 'nobody@example.com'])->assertNoContent();

    Notification::assertNothingSent();
});

it('ACC-004 refuses a bad token and keeps the old password', function () {
    User::factory()->create(['email' => 'pat@example.com', 'password' => 'old-password-123']);

    $this->postJson('/reset-password', [
        'token' => 'wrong',
        'email' => 'pat@example.com',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');

    $this->postJson('/sign-in', ['email' => 'pat@example.com', 'password' => 'old-password-123'])->assertNoContent();
});

it('ACC-004 sends at most three reset emails to one address in ten minutes', function () {
    foreach (range(1, 3) as $send) {
        $this->postJson('/forgot-password', ['email' => 'pat@example.com'])->assertNoContent();
    }

    $this->postJson('/forgot-password', ['email' => 'pat@example.com'])->assertTooManyRequests();
});
