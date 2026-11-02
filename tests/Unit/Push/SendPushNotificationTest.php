<?php

use App\Jobs\SendPushNotification;
use App\Models\User;
use App\Notifications\PushNotification;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;

function pushUser(): User
{
    $user = new User(['name' => 'Sam Lifter', 'email' => 'sam@example.com']);
    $user->id = 7;

    return $user;
}

it('NOTIF-011 puts the screen\'s URL in the push payload', function () {
    $message = (new PushNotification('Weigh-in', 'Time to weigh in.', '/today/weigh-in'))->toWebPush(pushUser());

    expect($message->toArray())->toBe([
        'title' => 'Weigh-in',
        'body' => 'Time to weigh in.',
        'data' => ['url' => '/today/weigh-in'],
    ]);
});

it('NOTIF-011 sends over the Web Push channel', function () {
    expect((new PushNotification('t', 'b', '/'))->via(pushUser()))->toBe([WebPushChannel::class]);
});

it('NOTIF-011 refuses a URL that leaves the app', function (string $url) {
    new PushNotification('Weigh-in', 'Time to weigh in.', $url);
})->with(['https://evil.example.com/', '//evil.example.com/', 'today', 'javascript:alert(1)'])
    ->throws(InvalidArgumentException::class);

it('NOTIF-010 the sender job notifies the user when the instance has VAPID keys', function () {
    config(['webpush.vapid.public_key' => 'public-key', 'webpush.vapid.private_key' => 'private-key']);
    Notification::fake();
    $user = pushUser();

    (new SendPushNotification($user, 'Weigh-in', 'Time to weigh in.', '/today/weigh-in'))->handle();

    Notification::assertSentTo($user, PushNotification::class, fn (PushNotification $sent): bool => $sent->url === '/today/weigh-in');
});

it('NOTIF-010 the sender job sends nothing when the instance has no VAPID keys', function () {
    config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);
    Notification::fake();

    (new SendPushNotification(pushUser(), 'Weigh-in', 'Time to weigh in.', '/today/weigh-in'))->handle();

    Notification::assertNothingSent();
});
