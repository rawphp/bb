<?php

use App\Jobs\SendPushNotification;
use App\Models\PushSubscription;
use App\Models\User;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\SubscriptionInterface;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\ReportHandler;
use NotificationChannels\WebPush\WebPushChannel;

/** Stands in for the push services: records what the real channel queues and answers each endpoint with a status. */
final class FakePushServices extends WebPush
{
    /** @var list<SubscriptionInterface> */
    public array $queued = [];

    /** @param array<string, int> $statuses */
    public function __construct(private readonly array $statuses = []) {}

    public function queueNotification(SubscriptionInterface $subscription, ?string $payload = null, array $options = [], array $auth = []): void
    {
        $this->queued[] = $subscription;
    }

    public function flush(?int $batchSize = null): Generator
    {
        foreach ($this->queued as $subscription) {
            $status = $this->statuses[$subscription->getEndpoint()] ?? 201;

            yield new MessageSentReport(new Request('POST', $subscription->getEndpoint()), new Response($status), $status < 300, $status < 300 ? 'OK' : 'Failed');
        }
    }
}

/** The real WebPushChannel and ReportHandler, with only the push services faked. */
function pushServices(array $statuses = []): FakePushServices
{
    config(['webpush.vapid.public_key' => 'public-key', 'webpush.vapid.private_key' => 'private-key']);
    $services = new FakePushServices($statuses);
    app()->instance(WebPushChannel::class, new WebPushChannel($services, app()->make(ReportHandler::class)));

    return $services;
}

function send(User $user): void
{
    (new SendPushNotification($user, 'Weigh-in', 'Time to weigh in.', '/today/weigh-in'))->handle();
}

it('NOTIF-010 sends to every browser of the user and no one else\'s, encoded as aes128gcm', function () {
    $services = pushServices();
    [$user, $other] = User::factory()->count(2)->create();
    $mine = PushSubscription::factory()->for($user)->count(2)->create();
    PushSubscription::factory()->for($other)->create();

    send($user);

    expect(collect($services->queued)->map->getEndpoint()->all())->toEqualCanonicalizing($mine->pluck('endpoint')->all())
        // The row stores no encoding; the channel's default is the one Safari needs.
        ->and(collect($services->queued)->map->getContentEncoding()->unique()->all())->toBe(['aes128gcm']);
});

it('NOTIF-010 deletes a subscription the push service says has expired and keeps the rest', function () {
    $user = User::factory()->create();
    [$expired, $live] = PushSubscription::factory()->for($user)->count(2)->create();
    pushServices([$expired->endpoint => 410]);

    send($user);

    expect(PushSubscription::query()->pluck('id')->all())->toBe([$live->id]);
});

it('NOTIF-010 never posts to a stored endpoint whose host is not a known push service', function () {
    $services = pushServices();
    $user = User::factory()->create();
    $allowed = PushSubscription::factory()->for($user)->create();
    PushSubscription::factory()->for($user)->create(['endpoint' => 'https://169.254.169.254/latest/meta-data']);

    send($user);

    expect(collect($services->queued)->map->getEndpoint()->all())->toBe([$allowed->endpoint]);
});

it('NOTIF-010 logs a send the push service refused, but not an expired subscription', function () {
    Log::spy();
    $user = User::factory()->create();
    [$refused, $expired] = PushSubscription::factory()->for($user)->count(2)->create();
    pushServices([$refused->endpoint => 503, $expired->endpoint => 410]);

    send($user);

    Log::shouldHaveReceived('warning')->once()->with('Web Push send failed', Mockery::on(
        fn (array $context): bool => $context['subscription'] === $refused->id && $context['status'] === 503 && $context['host'] === 'fcm.googleapis.com',
    ));
});

it('ACC-005 drops a queued push whose user deleted their account', function () {
    pushServices();
    $user = User::factory()->create();
    $job = new SendPushNotification($user, 'Weigh-in', 'Time to weigh in.', '/today/weigh-in');
    $user->delete();

    Queue::connection('sync')->push($job);
})->throwsNoExceptions();
