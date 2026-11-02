<?php

use App\Jobs\EmailPasswordResetLink;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

// The broker keeps its tokens in the database, so the job runs on it.
uses(RefreshDatabase::class);

it('ACC-004 forgets the token when the mail fails, so the retry sends the link', function () {
    $user = User::factory()->create(['email' => 'pat@example.com']);
    Event::listen(NotificationSending::class, fn () => throw new RuntimeException('SMTP is down'));

    expect(fn () => (new EmailPasswordResetLink('pat@example.com'))->handle())->toThrow(RuntimeException::class, 'SMTP is down');
    expect(DB::table('password_reset_tokens')->count())->toBe(0);

    Notification::fake();
    (new EmailPasswordResetLink('pat@example.com'))->handle();

    Notification::assertSentTo($user, ResetPassword::class);
});

it('ACC-004 sends nothing for an email with no account', function () {
    Notification::fake();

    (new EmailPasswordResetLink('nobody@example.com'))->handle();

    Notification::assertNothingSent();
});
