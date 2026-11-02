<?php

use App\Actions\Account\RedeemSignInCode;
use App\Jobs\EmailSignInCode;
use App\Models\User;
use App\Notifications\SignInCode;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

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

function wrongCode(string $code): string
{
    return $code === '000000' ? '111111' : '000000';
}

it('ACC-001 emails a six-digit code and signs in with it once', function () {
    $user = User::factory()->create(['email' => 'sam@example.com']);

    $this->postJson('/sign-in/code', ['email' => 'Sam@Example.com'])->assertNoContent();
    $code = emailedCode($user);
    expect($code)->toMatch('/^\d{6}$/')
        ->and((new SignInCode($code))->toMail($user)->render()->toHtml())->toContain($code);

    $this->postJson('/sign-in/code/verify', ['email' => 'Sam@Example.com', 'code' => $code])->assertNoContent();
    $this->assertAuthenticatedAs($user, 'web');

    $this->postJson('/sign-out')->assertNoContent();
    $this->postJson('/sign-in/code/verify', ['email' => 'sam@example.com', 'code' => $code])
        ->assertUnprocessable()->assertJsonValidationErrors(['code' => RedeemSignInCode::REFUSED]);
});

it('ACC-001 queues the mail, so every email gets the same quick answer', function () {
    Queue::fake();
    $user = User::factory()->create(['email' => 'sam@example.com']);

    $this->postJson('/sign-in/code', ['email' => 'sam@example.com'])->assertNoContent();
    $this->postJson('/sign-in/code', ['email' => 'nobody@example.com'])->assertNoContent();

    Queue::assertPushed(EmailSignInCode::class, fn (EmailSignInCode $job) => $job->email === 'sam@example.com');
    Queue::assertPushed(EmailSignInCode::class, fn (EmailSignInCode $job) => $job->email === 'nobody@example.com');
    expect(new EmailSignInCode('sam@example.com'))->toBeInstanceOf(ShouldBeEncrypted::class);
    Notification::assertNothingSent();
    expect($user->pendingSignInCode()->exists())->toBeFalse();
});

it('ACC-001 answers an unknown email the same way and sends nothing', function () {
    $this->postJson('/sign-in/code', ['email' => 'nobody@example.com'])->assertNoContent();

    Notification::assertNothingSent();
    $this->postJson('/sign-in/code/verify', ['email' => 'nobody@example.com', 'code' => '123456'])
        ->assertUnprocessable()->assertJsonValidationErrors(['code' => RedeemSignInCode::REFUSED]);
});

it('ACC-001 spends one bcrypt on a code check whether or not the email has an account', function () {
    $user = User::factory()->create(['email' => 'sam@example.com']);
    $this->postJson('/sign-in/code', ['email' => 'sam@example.com']);
    $wrong = wrongCode(emailedCode($user));

    $hasher = new class(['rounds' => 4]) extends BcryptHasher
    {
        /** @var list<string> */
        public array $calls = [];

        public function make(#[SensitiveParameter] $value, array $options = []): string
        {
            $this->calls[] = 'make';

            return parent::make($value, $options);
        }

        public function check(#[SensitiveParameter] $value, $hashedValue, array $options = []): bool
        {
            $this->calls[] = 'check';

            return parent::check($value, $hashedValue, $options);
        }
    };
    Hash::extend('counting', fn () => $hasher);
    config(['hashing.driver' => 'counting']);
    Hash::forgetDrivers();

    $this->postJson('/sign-in/code/verify', ['email' => 'nobody@example.com', 'code' => $wrong])->assertUnprocessable();
    expect($hasher->calls)->toBe(['make']);

    $hasher->calls = [];
    $this->postJson('/sign-in/code/verify', ['email' => 'sam@example.com', 'code' => $wrong])->assertUnprocessable();
    expect($hasher->calls)->toBe(['check']);
});

it('ACC-001 refuses a code after ten minutes', function () {
    $user = User::factory()->create(['email' => 'sam@example.com']);
    $this->postJson('/sign-in/code', ['email' => 'sam@example.com']);
    $code = emailedCode($user);

    $this->travel(10)->minutes();

    $this->postJson('/sign-in/code/verify', ['email' => 'sam@example.com', 'code' => $code])
        ->assertUnprocessable()->assertJsonValidationErrors(['code' => RedeemSignInCode::REFUSED]);
    $this->assertGuest();
});

it('ACC-001 allows five tries, then needs a new code', function () {
    $user = User::factory()->create(['email' => 'sam@example.com']);
    $this->postJson('/sign-in/code', ['email' => 'sam@example.com']);
    $code = emailedCode($user);

    foreach (range(1, 5) as $try) {
        $this->postJson('/sign-in/code/verify', ['email' => 'sam@example.com', 'code' => wrongCode($code)])
            ->assertUnprocessable()->assertJsonValidationErrors(['code' => RedeemSignInCode::REFUSED]);
    }
    $this->postJson('/sign-in/code/verify', ['email' => 'sam@example.com', 'code' => $code])
        ->assertUnprocessable()->assertJsonValidationErrors(['code' => RedeemSignInCode::REFUSED]);
    $this->assertGuest();

    Notification::fake();
    $this->travel(61)->seconds();
    $this->postJson('/sign-in/code', ['email' => 'sam@example.com']);
    $this->postJson('/sign-in/code/verify', ['email' => 'sam@example.com', 'code' => emailedCode($user)])
        ->assertNoContent();
    $this->assertAuthenticatedAs($user);
});

it('ACC-001 locks the code row while it checks a guess, so parallel guesses take turns', function () {
    $user = User::factory()->create(['email' => 'sam@example.com']);
    $this->postJson('/sign-in/code', ['email' => 'sam@example.com']);
    $wrong = wrongCode(emailedCode($user));

    DB::enableQueryLog();
    $this->postJson('/sign-in/code/verify', ['email' => 'sam@example.com', 'code' => $wrong])->assertUnprocessable();

    $locked = collect(DB::getQueryLog())->pluck('query')
        ->filter(fn (string $sql) => str_contains($sql, 'pending_sign_in_codes') && str_contains($sql, 'for update'));
    expect($locked)->toHaveCount(1);
});

/** Posts from one IP, a few seconds apart, so the guest group's ten a minute never applies. */
function fromIp(string $ip, string $uri, array $body)
{
    test()->travel(7)->seconds();

    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->postJson($uri, $body);
}

function wrongFrom(string $ip, string $email = 'sam@example.com')
{
    return fromIp($ip, '/sign-in/code/verify', ['email' => $email, 'code' => '123456']);
}

it('ACC-001 caps wrong codes per address and IP: ten an hour, twenty a day; the owner on another IP still signs in', function () {
    $user = User::factory()->create(['email' => 'sam@example.com']);

    foreach (range(1, 10) as $try) {
        wrongFrom('10.0.0.66')->assertJsonValidationErrors(['code' => RedeemSignInCode::REFUSED]);
    }
    wrongFrom('10.0.0.66', 'SAM@example.com')->assertJsonValidationErrors(['code' => RedeemSignInCode::TOO_MANY]);

    fromIp('127.0.0.1', '/sign-in/code', ['email' => 'sam@example.com'])->assertNoContent();
    fromIp('127.0.0.1', '/sign-in/code/verify', ['email' => 'sam@example.com', 'code' => emailedCode($user)])->assertNoContent();
    $this->postJson('/sign-out');

    $this->travel(1)->hours();
    foreach (range(1, 10) as $try) {
        wrongFrom('10.0.0.66')->assertJsonValidationErrors(['code' => RedeemSignInCode::REFUSED]);
    }
    $this->travel(1)->hours();
    wrongFrom('10.0.0.66')->assertJsonValidationErrors(['code' => RedeemSignInCode::TOO_MANY]);

    $this->travel(1)->days();
    wrongFrom('10.0.0.66')->assertJsonValidationErrors(['code' => RedeemSignInCode::REFUSED]);
});

it('ACC-001 caps wrong codes per address from any number of IPs at fifty a day', function () {
    $user = User::factory()->create(['email' => 'sam@example.com']);

    foreach (range(1, 50) as $ip) {
        wrongFrom('10.0.1.'.$ip)->assertJsonValidationErrors(['code' => RedeemSignInCode::REFUSED]);
    }
    wrongFrom('10.0.2.1')->assertJsonValidationErrors(['code' => RedeemSignInCode::TOO_MANY]);
    wrongFrom('10.0.2.1', 'pat@example.com')->assertJsonValidationErrors(['code' => RedeemSignInCode::REFUSED]);

    // Past this cap the owner's right code is refused too: the price of bounding guesses
    // from many IPs. The password still signs in.
    fromIp('127.0.0.1', '/sign-in/code', ['email' => 'sam@example.com']);
    fromIp('127.0.0.1', '/sign-in/code/verify', ['email' => 'sam@example.com', 'code' => emailedCode($user)])
        ->assertJsonValidationErrors(['code' => RedeemSignInCode::TOO_MANY]);
});

it('ACC-001 counts only refused codes toward the caps, and unknown emails the same way', function () {
    $user = User::factory()->create(['email' => 'sam@example.com']);

    foreach (range(1, 9) as $try) {
        wrongFrom('10.0.0.66')->assertUnprocessable();
    }
    fromIp('10.0.0.66', '/sign-in/code', ['email' => 'sam@example.com']);
    fromIp('10.0.0.66', '/sign-in/code/verify', ['email' => 'sam@example.com', 'code' => emailedCode($user)])->assertNoContent();
    $this->postJson('/sign-out');

    wrongFrom('10.0.0.66')->assertJsonValidationErrors(['code' => RedeemSignInCode::REFUSED]);
    wrongFrom('10.0.0.66')->assertJsonValidationErrors(['code' => RedeemSignInCode::TOO_MANY]);

    foreach (range(1, 10) as $try) {
        wrongFrom('10.0.0.66', 'nobody@example.com')->assertJsonValidationErrors(['code' => RedeemSignInCode::REFUSED]);
    }
    wrongFrom('10.0.0.66', 'nobody@example.com')->assertJsonValidationErrors(['code' => RedeemSignInCode::TOO_MANY]);
});

it('ACC-001 starts a new session and CSRF token on code sign-in', function () {
    $user = User::factory()->create(['email' => 'sam@example.com']);
    $this->postJson('/sign-in/code', ['email' => 'sam@example.com']);
    $code = emailedCode($user);
    $this->startSession();
    $id = session()->getId();
    $token = session()->token();

    $this->postJson('/sign-in/code/verify', ['email' => 'sam@example.com', 'code' => $code])->assertNoContent();

    // SessionGuard::login() regenerates the session too, so this stays green
    // if the controller's regenerate() goes; it fails only with both gone.
    expect(session()->getId())->not->toBe($id)
        ->and(session()->token())->not->toBe($token);
});

it('ACC-001 replaces the old code when a new one is sent', function () {
    $user = User::factory()->create(['email' => 'sam@example.com']);
    $this->postJson('/sign-in/code', ['email' => 'sam@example.com']);
    $first = emailedCode($user);

    Notification::fake();
    $this->travel(61)->seconds();
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

it('ACC-001 sends at most three codes to one address from one IP in ten minutes', function () {
    User::factory()->create(['email' => 'sam@example.com']);

    foreach (range(1, 3) as $send) {
        $this->postJson('/sign-in/code', ['email' => 'sam@example.com'])->assertNoContent();
    }
    $this->postJson('/sign-in/code', ['email' => 'SAM@example.com'])->assertTooManyRequests();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->postJson('/sign-in/code', ['email' => 'sam@example.com'])->assertNoContent();

    $this->travel(10)->minutes();
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->postJson('/sign-in/code', ['email' => 'sam@example.com'])->assertNoContent();
});

it('ACC-001 sends one code a minute, and a stranger on another IP cannot block the owner', function () {
    $user = User::factory()->create(['email' => 'sam@example.com']);

    foreach (range(1, 3) as $send) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.66'])
            ->postJson('/sign-in/code', ['email' => 'sam@example.com'])->assertNoContent();
    }
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.66'])
        ->postJson('/sign-in/code', ['email' => 'sam@example.com'])->assertTooManyRequests();
    // Reset links have their own count.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.66'])
        ->postJson('/forgot-password', ['email' => 'sam@example.com'])->assertNoContent();

    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->postJson('/sign-in/code', ['email' => 'sam@example.com'])->assertNoContent();
    Notification::assertSentToTimes($user, SignInCode::class, 1);

    $this->travel(61)->seconds();
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->postJson('/sign-in/code', ['email' => 'sam@example.com'])->assertNoContent();
    Notification::assertSentToTimes($user, SignInCode::class, 2);
});
