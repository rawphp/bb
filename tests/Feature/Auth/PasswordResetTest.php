<?php

use App\Jobs\EmailPasswordResetLink;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

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
        'email' => 'Pat+Tag@example.com',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertNoContent();

    $this->postJson('/sign-in', ['email' => 'pat+tag@example.com', 'password' => 'old-password-123'])->assertUnprocessable();
    $this->postJson('/sign-in', ['email' => 'pat+tag@example.com', 'password' => 'new-password-123'])->assertNoContent();
});

it('ACC-004 opens the reset link in the app shell', function () {
    $user = User::factory()->create(['email' => 'pat@example.com']);
    $this->postJson('/forgot-password', ['email' => 'pat@example.com']);

    $this->withoutVite()->get('/reset-password?'.http_build_query(['token' => resetToken($user), 'email' => 'pat@example.com']))
        ->assertOk()
        ->assertSee('<div id="app"></div>', escape: false);
});

it('ACC-004 ends old remember-me cookies by rotating the remember token', function () {
    $user = User::factory()->create(['email' => 'pat@example.com']);
    $user->forceFill(['remember_token' => 'stolen-cookie-token'])->save();
    $this->postJson('/forgot-password', ['email' => 'pat@example.com']);

    $this->postJson('/reset-password', [
        'token' => resetToken($user),
        'email' => 'pat@example.com',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertNoContent();

    expect($user->fresh()->remember_token)->not->toBeNull()->not->toBe('stolen-cookie-token');
});

it('ACC-004 queues the mail, so every email gets the same quick answer', function () {
    Queue::fake();
    User::factory()->create(['email' => 'pat@example.com']);

    $this->postJson('/forgot-password', ['email' => 'pat@example.com'])->assertNoContent();
    $this->postJson('/forgot-password', ['email' => 'nobody@example.com'])->assertNoContent();

    Queue::assertPushed(EmailPasswordResetLink::class, fn (EmailPasswordResetLink $job) => $job->email === 'pat@example.com');
    Queue::assertPushed(EmailPasswordResetLink::class, fn (EmailPasswordResetLink $job) => $job->email === 'nobody@example.com');
    expect(new EmailPasswordResetLink('pat@example.com'))->toBeInstanceOf(ShouldBeEncrypted::class);
    Notification::assertNothingSent();
});

it('ACC-004 answers an unknown email the same way and sends nothing', function () {
    $this->postJson('/forgot-password', ['email' => 'nobody@example.com'])->assertNoContent();

    Notification::assertNothingSent();
});

it('ACC-004 refuses a new password that is too short or mistyped, and keeps the old one', function (string $password, string $confirmation) {
    $user = User::factory()->create(['email' => 'pat@example.com', 'password' => 'old-password-123']);
    $this->postJson('/forgot-password', ['email' => 'pat@example.com']);

    $this->postJson('/reset-password', [
        'token' => resetToken($user),
        'email' => 'pat@example.com',
        'password' => $password,
        'password_confirmation' => $confirmation,
    ])->assertUnprocessable()->assertJsonValidationErrors('password');

    $this->postJson('/sign-in', ['email' => 'pat@example.com', 'password' => 'old-password-123'])->assertNoContent();
})->with([
    'too short' => ['short12', 'short12'],
    'confirmation does not match' => ['new-password-123', 'new-password-124'],
]);

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

it('ACC-004 sends at most three reset emails to one address from one IP in ten minutes', function () {
    foreach (range(1, 3) as $send) {
        $this->postJson('/forgot-password', ['email' => 'pat@example.com'])->assertNoContent();
    }

    $this->postJson('/forgot-password', ['email' => 'Pat@example.com'])->assertTooManyRequests();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->postJson('/forgot-password', ['email' => 'pat@example.com'])->assertNoContent();
});
