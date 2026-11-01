<?php

use App\Actions\Account\SendSignInCode;
use App\Models\PendingSignInCode;
use App\Models\User;
use App\Notifications\SignInCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

// The action's job is the one row it keeps per user in pending_sign_in_codes, so it runs on the database.
uses(RefreshDatabase::class);

beforeEach(fn () => Notification::fake());

it('ACC-001 stores only a hash of the code it mails, with no tries used', function () {
    $user = User::factory()->create(['email' => 'sam@example.com']);

    (new SendSignInCode)('sam@example.com');

    $sent = null;
    Notification::assertSentTo($user, SignInCode::class, function (SignInCode $n) use (&$sent) {
        $sent = $n->code;

        return true;
    });
    $pending = $user->pendingSignInCode()->sole();
    expect($pending->code_hash)->not->toBe($sent)
        ->and(Hash::check($sent, $pending->code_hash))->toBeTrue()
        ->and($pending->failed_tries)->toBe(0);
});

it('ACC-001 replaces the code and its used tries when a new one is sent', function () {
    $user = User::factory()->create(['email' => 'sam@example.com']);
    $user->pendingSignInCode()->create(['code_hash' => Hash::make('123456'), 'failed_tries' => 4, 'issued_at' => now()->subMinutes(5)]);

    (new SendSignInCode)('sam@example.com');

    $pending = $user->pendingSignInCode()->sole();
    expect($pending->failed_tries)->toBe(0)
        ->and(Hash::check('123456', $pending->code_hash))->toBeFalse()
        ->and($pending->issued_at->isSameMinute(now()))->toBeTrue();
});

it('ACC-001 keeps the code and sends nothing within a minute of the last one', function () {
    // issued_at is stored to the second, so a moving clock could cross the minute mid-test.
    $this->freezeSecond();
    $user = User::factory()->create(['email' => 'sam@example.com']);
    $user->pendingSignInCode()->create(['code_hash' => Hash::make('123456'), 'failed_tries' => 2, 'issued_at' => now()->subSeconds(59)]);

    (new SendSignInCode)('sam@example.com');

    Notification::assertNothingSent();
    $pending = $user->pendingSignInCode()->sole();
    expect(Hash::check('123456', $pending->code_hash))->toBeTrue()
        ->and($pending->failed_tries)->toBe(2);
});

it('ACC-001 stores and sends nothing for an email with no account', function () {
    (new SendSignInCode)('nobody@example.com');

    Notification::assertNothingSent();
    expect(PendingSignInCode::count())->toBe(0);
});

it('ACC-001 forgets the code when the mail fails, so the retry sends a new one', function () {
    $user = User::factory()->create(['email' => 'sam@example.com']);
    Notification::swap(new ChannelManager(app()));
    Event::listen(NotificationSending::class, fn () => throw new RuntimeException('SMTP is down'));

    expect(fn () => (new SendSignInCode)('sam@example.com'))->toThrow(RuntimeException::class, 'SMTP is down');
    expect($user->pendingSignInCode()->exists())->toBeFalse();

    Notification::fake();
    (new SendSignInCode)('sam@example.com');

    Notification::assertSentTo($user, SignInCode::class);
});
