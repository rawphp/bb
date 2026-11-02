<?php

use App\Actions\Notifications\SendDueReminders;
use App\Jobs\SendUserReminders;
use App\Models\DayTarget;
use App\Models\Mesocycle;
use App\Models\NotificationPref;
use App\Models\PlanWeek;
use App\Models\Profile;
use App\Models\PushSubscription;
use App\Models\SentReminder;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Notifications\PushNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TodaySeed;

// The server sends each reminder once, when it falls due (NOTIF-001 to NOTIF-005, NOTIF-007), from the stored plan.
// Ported from rp-clone's TodayRemindersTests on the same fixture: the plan starts Saturday 2026-09-12 with Saturday
// reviews and the deload from 2026-10-03; weigh-ins Wednesday and Saturday at 07:00; training Monday, Wednesday and
// Friday at 17:30; Wednesday 23's lunch is checked in, its weigh-in logged, and Monday 21's session done.

beforeEach(function () {
    config(['webpush.vapid.public_key' => 'public-key', 'webpush.vapid.private_key' => 'private-key']);
    Notification::fake();
});
afterEach(fn () => Carbon::setTestNow());

function remindedUser(): User
{
    $user = TodaySeed::user();
    TodaySeed::targets($user);
    TodaySeed::wednesday($user, TodaySeed::mesocycle($user));
    PushSubscription::factory()->for($user)->create();

    return $user;
}

/** @return list<string> "title | body | url" of everything sent so far */
function sentLines(User $user): array
{
    $lines = [];
    foreach (Notification::sent($user, PushNotification::class) as $notification) {
        $lines[] = "{$notification->title} | {$notification->body} | {$notification->url}";
    }

    return $lines;
}

it('NOTIF-001 sends the weigh-in reminder at the scheduled time', function () {
    $user = remindedUser();

    Carbon::setTestNow('2026-09-26 07:00:00');
    app(SendDueReminders::class)($user, CarbonImmutable::now());

    expect(sentLines($user))->toBe(['Weigh-in | Time to weigh in | /today?date=2026-09-26&open=weigh-in']);
});

it('NOTIF-001 a day with a logged weigh-in has no reminder', function () {
    $user = remindedUser();

    Carbon::setTestNow('2026-09-23 07:00:00');
    app(SendDueReminders::class)($user, CarbonImmutable::now());

    Notification::assertNothingSent();
});

it('NOTIF-001 sends each reminder once, and drops one more than 10 minutes late', function () {
    $user = remindedUser();

    foreach (['2026-09-26 07:00:00', '2026-09-26 07:01:00', '2026-09-26 07:09:00'] as $at) {
        Carbon::setTestNow($at);
        app(SendDueReminders::class)($user, CarbonImmutable::now());
    }
    expect(sentLines($user))->toHaveCount(1);

    $late = remindedUser();
    Carbon::setTestNow('2026-09-26 07:10:00');
    app(SendDueReminders::class)($late, CarbonImmutable::now());
    expect(sentLines($late))->toBe([]);
});

it('NOTIF-002 meal reminders are off by default, and on they come at each meal not checked in', function () {
    $user = remindedUser();

    Carbon::setTestNow('2026-09-23 19:30:00');
    app(SendDueReminders::class)($user, CarbonImmutable::now());
    expect(sentLines($user))->toBe([]);

    NotificationPref::query()->create(['user_id' => $user->id, 'kind' => 'meal', 'on' => true]);
    Carbon::setTestNow('2026-09-23 12:30:00');
    app(SendDueReminders::class)($user, CarbonImmutable::now());
    expect(sentLines($user))->toBe([]);

    Carbon::setTestNow('2026-09-23 19:31:00');
    app(SendDueReminders::class)($user, CarbonImmutable::now());
    expect(sentLines($user))->toBe(['Dinner | Time to eat | /today?date=2026-09-23']);
});

it('NOTIF-003 sends the workout reminder an hour before a planned session, with its pre-workout meal', function () {
    $user = remindedUser();

    Carbon::setTestNow('2026-09-23 16:30:00');
    app(SendDueReminders::class)($user, CarbonImmutable::now());
    Carbon::setTestNow('2026-09-25 16:30:00');
    app(SendDueReminders::class)($user, CarbonImmutable::now());

    expect(sentLines($user))->toBe([
        'Workout in 1 hour | Lower A · Lunch checked in | /today?date=2026-09-23&open=workout',
        'Workout in 1 hour | Upper B · Lunch not checked in yet | /today?date=2026-09-25&open=workout',
    ]);
});

it('NOTIF-003 a session already done has no reminder', function () {
    $user = remindedUser();

    Carbon::setTestNow('2026-09-21 16:30:00');
    app(SendDueReminders::class)($user, CarbonImmutable::now());

    expect(sentLines($user))->toBe([]);
});

it('NOTIF-004 sends the weekly review on review day once a full plan week has passed', function () {
    $user = remindedUser();

    foreach (['2026-09-12 09:00:00', '2026-09-19 09:00:00', '2026-09-20 09:00:00'] as $at) {
        Carbon::setTestNow($at);
        app(SendDueReminders::class)($user, CarbonImmutable::now());
    }

    expect(sentLines($user))->toBe(['Weekly review available | Your week is ready to review | /today?date=2026-09-19']);
});

it('NOTIF-005 sends next mesocycle on the first day of the deload week only', function () {
    $user = remindedUser();

    foreach (['2026-10-03 09:00:00', '2026-10-04 09:00:00'] as $at) {
        Carbon::setTestNow($at);
        app(SendDueReminders::class)($user, CarbonImmutable::now());
    }

    expect(sentLines($user))->toContain('Build your next mesocycle | It is a deload week. Plan what comes next. | /today?date=2026-10-03')
        ->and(array_filter(sentLines($user), fn (string $line) => str_starts_with($line, 'Build your next')))->toHaveCount(1);
});

it('NOTIF-005 has no next mesocycle reminder when the mesocycle ended before its deload', function () {
    // As rp-clone's test: no plan week is flagged a deload, so the deload is the mesocycle's own last week.
    $user = remindedUser();
    PlanWeek::query()->where('user_id', $user->id)->delete();
    $control = remindedUser();
    PlanWeek::query()->where('user_id', $control->id)->delete();
    Mesocycle::query()->where('user_id', $user->id)->update(['ended_on' => '2026-09-25']);

    Carbon::setTestNow('2026-10-03 09:00:00');
    app(SendDueReminders::class)($user, CarbonImmutable::now());

    app(SendDueReminders::class)($control, CarbonImmutable::now());

    expect(array_filter(sentLines($user), fn (string $line) => str_starts_with($line, 'Build your next')))->toBe([])
        ->and(array_filter(sentLines($control), fn (string $line) => str_starts_with($line, 'Build your next')))->toHaveCount(1);
});

it('NOTIF-007 a kind switched off is not sent', function () {
    $user = remindedUser();
    NotificationPref::query()->create(['user_id' => $user->id, 'kind' => 'weigh_in', 'on' => false]);

    Carbon::setTestNow('2026-09-26 07:00:00');
    app(SendDueReminders::class)($user, CarbonImmutable::now());

    Notification::assertNothingSent();
});

it('NOTIF-001 reminders:send queues one job per subscribed user, so one user\'s failure cannot hold up the rest', function () {
    $first = remindedUser();
    $second = remindedUser();
    TodaySeed::user();

    Queue::fake();
    Carbon::setTestNow('2026-09-26 07:00:00');
    $this->artisan('reminders:send')->assertSuccessful();

    Queue::assertPushed(SendUserReminders::class, 2);
    foreach ([$first, $second] as $user) {
        Queue::assertPushed(SendUserReminders::class, fn (SendUserReminders $job) => $job->user->is($user) && $job->now->eq(CarbonImmutable::now()));
    }
});

it('NOTIF-001 a reminder whose push cannot be queued is not marked sent, so the next pass sends it', function () {
    $user = remindedUser();
    Carbon::setTestNow('2026-09-26 07:00:00');

    $bus = Bus::getFacadeRoot();
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue down'));
    expect(fn () => app(SendDueReminders::class)($user, CarbonImmutable::now()))->toThrow(RuntimeException::class);
    expect(SentReminder::query()->count())->toBe(0);

    Bus::swap($bus);
    Carbon::setTestNow('2026-09-26 07:01:00');
    app(SendDueReminders::class)($user, CarbonImmutable::now());
    expect(sentLines($user))->toBe(['Weigh-in | Time to weigh in | /today?date=2026-09-26&open=weigh-in']);
});

it('NOTIF-002 a meal moved later after its reminder went out is reminded again at its new time', function () {
    $user = remindedUser();
    NotificationPref::query()->create(['user_id' => $user->id, 'kind' => 'meal', 'on' => true]);

    Carbon::setTestNow('2026-09-24 12:30:00');
    app(SendDueReminders::class)($user, CarbonImmutable::now());
    $day = DayTarget::query()->where('user_id', $user->id)->whereDate('date', '2026-09-24')->sole();
    $meals = $day->meals;
    $meals[1]['time'] = '13:00';
    $day->update(['meals' => $meals]);
    Carbon::setTestNow('2026-09-24 13:00:00');
    app(SendDueReminders::class)($user, CarbonImmutable::now());

    expect(sentLines($user))->toBe(['Lunch | Time to eat | /today?date=2026-09-24', 'Lunch | Time to eat | /today?date=2026-09-24']);
});

it('NOTIF-001 a pass that runs 9 minutes late still sends what fell due', function () {
    $user = remindedUser();

    Carbon::setTestNow('2026-09-26 07:09:00');
    app(SendDueReminders::class)($user, CarbonImmutable::now());

    expect(sentLines($user))->toBe(['Weigh-in | Time to weigh in | /today?date=2026-09-26&open=weigh-in']);
});

it('NOTIF-003 a session just after midnight is reminded at 23:30 the evening before', function () {
    $user = remindedUser();
    $user->profile()->update(['training_days' => [
        ['weekday' => 1, 'start' => '17:30', 'minutes' => 60],
        ['weekday' => 3, 'start' => '00:30', 'minutes' => 60],
        ['weekday' => 5, 'start' => '17:30', 'minutes' => 60],
    ]]);

    Carbon::setTestNow('2026-09-29 23:30:00');
    app(SendDueReminders::class)($user, CarbonImmutable::now());

    expect(sentLines($user))->toHaveCount(1)
        ->and(sentLines($user)[0])->toStartWith('Workout in 1 hour | ')->toEndWith(' | /today?date=2026-09-30&open=workout');
});

it('NOTIF-003 a skipped session has no reminder', function () {
    $user = remindedUser();
    WorkoutSession::factory()->for($user)->create([
        'mesocycle_id' => Mesocycle::query()->where('user_id', $user->id)->value('id'), 'week' => 2, 'day' => 3, 'is_skipped' => true,
    ]);

    Carbon::setTestNow('2026-09-25 16:30:00');
    app(SendDueReminders::class)($user, CarbonImmutable::now());

    expect(sentLines($user))->toBe([]);
});

it('NOTIF-001 nothing is sent before the plan starts, or before the profile exists', function () {
    $early = remindedUser();
    Carbon::setTestNow('2026-09-09 07:00:00');
    app(SendDueReminders::class)($early, CarbonImmutable::now());

    $noProfile = remindedUser();
    Profile::query()->where('user_id', $noProfile->id)->delete();
    Carbon::setTestNow('2026-09-26 07:00:00');
    app(SendDueReminders::class)($noProfile, CarbonImmutable::now());

    Notification::assertNothingSent();
});

it('NOTIF-001 reminders:send reaches every user with a subscribed browser, and only them', function () {
    $subscribed = remindedUser();
    $unsubscribed = TodaySeed::user();

    Carbon::setTestNow('2026-09-26 07:00:00');
    $this->artisan('reminders:send')->assertSuccessful();

    expect(sentLines($subscribed))->toHaveCount(1)
        ->and(SentReminder::query()->where('user_id', $unsubscribed->id)->count())->toBe(0);
    Notification::assertNotSentTo($unsubscribed, PushNotification::class);
});

it('NOTIF-001 reminders:send runs every minute, and the sent log is pruned daily', function () {
    $events = collect(app(Schedule::class)->events());
    $send = $events->first(fn ($event) => str_contains((string) $event->command, 'reminders:send'));
    $prune = $events->first(fn ($event) => str_contains((string) $event->command, 'model:prune') && str_contains((string) $event->command, 'SentReminder'));

    expect($send?->expression)->toBe('* * * * *')
        ->and($send->withoutOverlapping)->toBeTrue()
        ->and($send->expiresAt)->toBe(10)
        ->and($send->filtersPass(app()))->toBeTrue()
        ->and($prune?->expression)->toBe('0 0 * * *');

    // Without VAPID keys there is nowhere to send, so the pass does not run and claims nothing.
    config(['webpush.vapid.public_key' => null]);
    expect($send->filtersPass(app()))->toBeFalse();
});

it('NOTIF-001 the sent log keeps two days', function () {
    $user = remindedUser();
    Carbon::setTestNow('2026-09-26 07:00:00');
    app(SendDueReminders::class)($user, CarbonImmutable::now());

    Carbon::setTestNow('2026-09-28 06:59:00');
    expect((new SentReminder)->prunable()->count())->toBe(0);
    Carbon::setTestNow('2026-09-28 07:01:00');
    expect((new SentReminder)->prunable()->count())->toBe(1);
});
