<?php

use App\Actions\Notifications\SendDueReminders;
use App\Domain\Notifications\PlannedReminder;
use App\Domain\Notifications\ReminderPlanner;
use App\Jobs\SendUserReminders;
use App\Models\DayTarget;
use App\Models\Mesocycle;
use App\Models\NotificationPref;
use App\Models\Plan;
use App\Models\PlanWeek;
use App\Models\Profile;
use App\Models\PushSubscription;
use App\Models\ReminderMorning;
use App\Models\SentReminder;
use App\Models\User;
use App\Models\WeighInScheduleEntry;
use App\Models\WorkoutSession;
use App\Notifications\PushNotification;
use App\Support\Notifications\ReminderDays;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

it('NOTIF-004 PLAN-008 after a review-day change the weekly review is due on the new day and not the old one', function () {
    $user = remindedUser();
    // Weeks start Monday 09-07; from 09-28 they start Thursday, so week 4 starts Thursday 2026-10-08.
    Plan::query()->where('user_id', $user->id)->update(['start_date' => '2026-09-07', 'review_weekday' => 1]);
    $user->plan->reviewDayChanges()->create(['effective_on' => '2026-09-28', 'review_weekday' => 4]);

    $may = fn (string $date) => ReminderDays::mayHaveDue([$user->id], new DateTimeImmutable("{$date} 08:50:00"), new DateTimeImmutable("{$date} 09:00:00"));
    expect($may('2026-10-08'))->toBe([$user->id]);

    foreach (['2026-10-05 09:00:00', '2026-10-08 09:00:00'] as $at) {
        Carbon::setTestNow($at);
        app(SendDueReminders::class)($user, CarbonImmutable::now());
    }

    expect(array_values(array_filter(sentLines($user), fn (string $line) => str_starts_with($line, 'Weekly review'))))
        ->toBe(['Weekly review available | Your week is ready to review | /today?date=2026-10-08']);
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

it('NOTIF-001 reminders:send queues a job only for users who may have something due, with a few queries for them all', function () {
    $weighsNow = remindedUser();
    $weighsLater = remindedUser();
    WeighInScheduleEntry::query()->where('user_id', $weighsLater->id)->update(['time' => '08:00']);
    Queue::fake();

    // 03:00: nothing can be due for anyone, so nothing is queued.
    Carbon::setTestNow('2026-09-26 03:00:00');
    DB::enableQueryLog();
    $this->artisan('reminders:send')->assertSuccessful();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    DB::flushQueryLog();
    Queue::assertNothingPushed();
    expect($queries)->toBeLessThanOrEqual(6);

    // 07:00: only the user whose weigh-in is at 07:00.
    Carbon::setTestNow('2026-09-26 07:00:00');
    $this->artisan('reminders:send')->assertSuccessful();
    Queue::assertPushed(SendUserReminders::class, 1);
    Queue::assertPushed(SendUserReminders::class, fn (SendUserReminders $job) => $job->user->is($weighsNow));
});

it('NOTIF-004 NOTIF-005 NOTIF-007 at 09:00 a user with both morning switches off is not queued, and one with either on is', function () {
    $user = remindedUser();
    $reviewOff = remindedUser();
    NotificationPref::query()->create(['user_id' => $user->id, 'kind' => 'weekly_review', 'on' => false]);
    NotificationPref::query()->create(['user_id' => $user->id, 'kind' => 'next_mesocycle', 'on' => false]);
    NotificationPref::query()->create(['user_id' => $reviewOff->id, 'kind' => 'weekly_review', 'on' => false]);
    Queue::fake();

    Carbon::setTestNow('2026-09-26 09:00:00');
    $this->artisan('reminders:send')->assertSuccessful();

    Queue::assertPushed(SendUserReminders::class, 1);
    Queue::assertPushed(SendUserReminders::class, fn (SendUserReminders $job) => $job->user->is($reviewOff));
});

it('NOTIF-004 NOTIF-005 a user is queued once for the morning, even when the pass sent nothing', function () {
    // The plan starts today, so the review day has no full week behind it: nothing is due at 09:00.
    $user = remindedUser();
    Plan::query()->where('user_id', $user->id)->update(['start_date' => '2026-09-26']);

    Carbon::setTestNow('2026-09-26 09:00:00');
    $this->artisan('reminders:send')->assertSuccessful();
    expect(sentLines($user))->toBe([])
        // The judged morning has its own row; sent_reminders holds only reminders that went out.
        ->and(ReminderMorning::query()->where('user_id', $user->id)->pluck('date')->map->format('Y-m-d')->all())->toBe(['2026-09-26'])
        ->and(SentReminder::query()->where('user_id', $user->id)->count())->toBe(0);

    Queue::fake();
    foreach (['2026-09-26 09:01:00', '2026-09-26 09:05:00'] as $at) {
        Carbon::setTestNow($at);
        $this->artisan('reminders:send')->assertSuccessful();
    }
    Queue::assertNothingPushed();
});

it('NOTIF-004 NOTIF-005 at 09:00 only a user on a plan-week start may be due, and only until the morning reminder went out', function () {
    $user = remindedUser();
    Queue::fake();

    // Sunday: the plan's weeks start on Saturday, so neither morning reminder can fall today.
    Carbon::setTestNow('2026-09-27 09:00:00');
    $this->artisan('reminders:send')->assertSuccessful();
    Queue::assertNothingPushed();

    // Saturday, the review day: queued at 09:00.
    Carbon::setTestNow('2026-09-26 09:00:00');
    $this->artisan('reminders:send')->assertSuccessful();
    Queue::assertPushed(SendUserReminders::class, 1);

    // Once the morning reminder is sent, the next minutes of the window queue nothing more.
    SentReminder::query()->create(['user_id' => $user->id, 'reminder_id' => 'weekly-review-2026-09-26', 'due_at' => '2026-09-26 09:00:00', 'sent_at' => '2026-09-26 09:00:00']);
    Carbon::setTestNow('2026-09-26 09:01:00');
    $this->artisan('reminders:send')->assertSuccessful();
    Queue::assertPushed(SendUserReminders::class, 1);
});

it('NOTIF-005 a plan starting mid-week may have its morning reminder on the start day, not the day after', function () {
    $user = remindedUser();
    // Week 0 begins on the start, a Thursday; later weeks begin on Monday, the review day.
    Plan::query()->where('user_id', $user->id)->update(['start_date' => '2026-09-10', 'review_weekday' => 1]);
    $at = fn (string $date) => ReminderDays::mayHaveDue([$user->id], new DateTimeImmutable("{$date} 08:50:00"), new DateTimeImmutable("{$date} 09:00:00"));

    expect($at('2026-09-10'))->toBe([$user->id])
        ->and($at('2026-09-11'))->toBe([])
        ->and($at('2026-09-14'))->toBe([$user->id]);
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

it('NOTIF-002 a meal moved earlier, inside the window, after its reminder went out is not reminded twice', function () {
    $user = remindedUser();
    NotificationPref::query()->create(['user_id' => $user->id, 'kind' => 'meal', 'on' => true]);

    Carbon::setTestNow('2026-09-24 12:30:00');
    app(SendDueReminders::class)($user, CarbonImmutable::now());
    $day = DayTarget::query()->where('user_id', $user->id)->whereDate('date', '2026-09-24')->sole();
    $meals = $day->meals;
    $meals[1]['time'] = '12:25';
    $day->update(['meals' => $meals]);
    Carbon::setTestNow('2026-09-24 12:32:00');
    app(SendDueReminders::class)($user, CarbonImmutable::now());

    expect(sentLines($user))->toBe(['Lunch | Time to eat | /today?date=2026-09-24']);
});

it('NOTIF-001 a queued pass judges what is due when it runs, and one more than 10 minutes late is dropped and logged', function () {
    $user = remindedUser();
    $queuedAt = CarbonImmutable::parse('2026-09-26 07:00:00');

    // 11 minutes late: the 07:00 weigh-in is past the window, and the pass is dropped without reading the plan.
    Log::spy();
    Carbon::setTestNow('2026-09-26 07:11:00');
    app()->call([new SendUserReminders($user, $queuedAt), 'handle']);
    expect(sentLines($user))->toBe([]);
    Log::shouldHaveReceived('warning')->once();

    // 9 minutes late: what fell due in the 10 minutes before it runs still goes out.
    Carbon::setTestNow('2026-09-26 07:09:00');
    app()->call([new SendUserReminders($user, $queuedAt), 'handle']);
    expect(sentLines($user))->toBe(['Weigh-in | Time to weigh in | /today?date=2026-09-26&open=weigh-in']);
});

it('NOTIF-001 a pass run late does not send what fell due more than 10 minutes before it ran', function () {
    $user = remindedUser();
    WeighInScheduleEntry::query()->where('user_id', $user->id)->update(['time' => '06:55']);

    Carbon::setTestNow('2026-09-26 07:09:00');
    app()->call([new SendUserReminders($user, CarbonImmutable::parse('2026-09-26 07:00:00')), 'handle']);

    expect(sentLines($user))->toBe([]);
});

it('NOTIF-001 two passes at once send a reminder once: the unique key stops the second claim', function () {
    $user = remindedUser();
    // Another pass claimed it between this pass's check and its insert.
    SentReminder::query()->create(['user_id' => $user->id, 'reminder_id' => 'weigh-in-2026-09-26', 'due_at' => '2026-09-26 07:00:00', 'sent_at' => '2026-09-26 06:59:00']);

    Carbon::setTestNow('2026-09-26 07:00:00');
    app(SendDueReminders::class)($user, CarbonImmutable::now());

    expect(sentLines($user))->toBe([])
        ->and(SentReminder::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('NOTIF-002 a reminder sent late and then moved earlier is not sent again', function () {
    $user = remindedUser();

    // Meals switched on at 12:37, so the 12:30 lunch first goes out with the 12:38 pass.
    Carbon::setTestNow('2026-09-24 12:38:00');
    NotificationPref::query()->create(['user_id' => $user->id, 'kind' => 'meal', 'on' => true]);
    app(SendDueReminders::class)($user, CarbonImmutable::now());
    $day = DayTarget::query()->where('user_id', $user->id)->whereDate('date', '2026-09-24')->sole();
    $meals = $day->meals;
    $meals[1]['time'] = '12:35';
    $day->update(['meals' => $meals]);
    Carbon::setTestNow('2026-09-24 12:39:00');
    app(SendDueReminders::class)($user, CarbonImmutable::now());

    expect(sentLines($user))->toBe(['Lunch | Time to eat | /today?date=2026-09-24']);
});

it('NOTIF-001 a pass just after midnight still sends what fell due late the evening before', function () {
    $user = remindedUser();
    WeighInScheduleEntry::query()->where('user_id', $user->id)->update(['time' => '23:55']);

    Carbon::setTestNow('2026-09-27 00:02:00');
    app(SendDueReminders::class)($user, CarbonImmutable::now());

    expect(sentLines($user))->toBe(['Weigh-in | Time to weigh in | /today?date=2026-09-26&open=weigh-in']);
});

it('NOTIF-001 NOTIF-004 a plan whose targets ended still reminds in today\'s week, as Today shows it', function () {
    $user = TodaySeed::user();
    TodaySeed::targets($user, '2026-09-20');
    PushSubscription::factory()->for($user)->create();

    foreach (['2026-10-24 07:00:00', '2026-10-24 09:00:00'] as $at) {
        Carbon::setTestNow($at);
        app(SendDueReminders::class)($user, CarbonImmutable::now());
    }

    expect(sentLines($user))->toBe([
        'Weigh-in | Time to weigh in | /today?date=2026-10-24&open=weigh-in',
        'Weekly review available | Your week is ready to review | /today?date=2026-10-24',
    ]);
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
    $alsoSubscribed = remindedUser();
    $unsubscribed = TodaySeed::user();

    Carbon::setTestNow('2026-09-26 07:00:00');
    $this->artisan('reminders:send')->assertSuccessful();

    // Both have the same plan, so the same reminder id: one user's send does not count as the other's.
    expect(sentLines($subscribed))->toBe(['Weigh-in | Time to weigh in | /today?date=2026-09-26&open=weigh-in'])
        ->and(sentLines($alsoSubscribed))->toBe(['Weigh-in | Time to weigh in | /today?date=2026-09-26&open=weigh-in'])
        ->and(SentReminder::query()->where('user_id', $unsubscribed->id)->count())->toBe(0);
    Notification::assertNotSentTo($unsubscribed, PushNotification::class);
});

it('NOTIF-001 reminders:send runs every minute, and the sent log is pruned daily', function () {
    $events = collect(app(Schedule::class)->events());
    $send = $events->first(fn ($event) => str_contains((string) $event->command, 'reminders:send'));
    $prune = $events->first(fn ($event) => str_contains((string) $event->command, 'model:prune') && str_contains((string) $event->command, 'SentReminder') && str_contains((string) $event->command, 'ReminderMorning'));

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

it('NOTIF-001 the cheap check finds every reminder the full plan makes, across a month of the fixture', function () {
    $user = remindedUser();
    NotificationPref::query()->create(['user_id' => $user->id, 'kind' => 'meal', 'on' => true]);
    // A session moved to another time, so its row's start is a source of its own.
    WorkoutSession::factory()->for($user)->create([
        'mesocycle_id' => Mesocycle::query()->where('user_id', $user->id)->value('id'), 'week' => 3, 'day' => 1,
        'planned_start_at' => '2026-09-28 06:15:00',
    ]);
    Carbon::setTestNow('2026-09-12 00:00:00');
    $days = ReminderDays::between($user, new DateTimeImmutable('2026-09-12'), new DateTimeImmutable('2026-10-09'));
    $planned = ReminderPlanner::plan($days, NotificationPref::switches($user));

    expect(collect($planned)->pluck('kind')->map->value->unique()->sort()->values()->all())
        ->toBe(['meal', 'next_mesocycle', 'weekly_review', 'weigh_in', 'workout'])
        ->and(collect($planned)->contains(fn (PlannedReminder $r) => $r->fireAt->format('Y-m-d H:i') === '2026-09-28 05:15'))->toBeTrue();
    foreach ($planned as $reminder) {
        expect(ReminderDays::mayHaveDue([$user->id], $reminder->fireAt->modify('-10 minutes'), $reminder->fireAt))->toBe([$user->id], $reminder->id);
    }
});

it('NOTIF-001 a minute with nothing due skips the full read of the plan', function () {
    $user = remindedUser();

    Carbon::setTestNow('2026-09-26 03:00:00');
    expect(ReminderDays::mayHaveDue([$user->id], CarbonImmutable::now()->subMinutes(10)->toDateTimeImmutable(), CarbonImmutable::now()->toDateTimeImmutable()))->toBe([]);

    DB::enableQueryLog();
    app(SendDueReminders::class)($user, CarbonImmutable::now());
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    DB::flushQueryLog();

    expect($queries)->toBeLessThanOrEqual(6);
    Notification::assertNothingSent();
});

it('NOTIF-004 the judged-morning log keeps two days', function () {
    $user = remindedUser();
    ReminderMorning::query()->create(['user_id' => $user->id, 'date' => '2026-09-26', 'judged_at' => '2026-09-26 09:00:00']);

    Carbon::setTestNow('2026-09-28 08:59:00');
    expect((new ReminderMorning)->prunable()->count())->toBe(0);
    Carbon::setTestNow('2026-09-28 09:01:00');
    expect((new ReminderMorning)->prunable()->count())->toBe(1);
});

it('SET-012 sends the weigh-in reminder at the user\'s local time', function (string $zone, array $at2100, array $at0700) {
    foreach (['2026-09-25 21:00:00' => $at2100, '2026-09-26 07:00:00' => $at0700] as $at => $lines) {
        $user = remindedUser();
        $user->forceFill(['time_zone' => $zone])->save();
        Carbon::setTestNow($at);
        app(SendDueReminders::class)($user, CarbonImmutable::now());

        expect(sentLines($user))->toBe($lines, $at);
    }
})->with([
    'Australia/Brisbane' => ['Australia/Brisbane', ['Weigh-in | Time to weigh in | /today?date=2026-09-26&open=weigh-in'], []],
    'UTC' => ['UTC', [], ['Weigh-in | Time to weigh in | /today?date=2026-09-26&open=weigh-in']],
]);

it('SET-012 sends the morning reminder at the user\'s local 09:00', function (string $zone, string $due, string $early) {
    $user = remindedUser();
    $user->forceFill(['time_zone' => $zone])->save();

    Carbon::setTestNow($early);
    app(SendDueReminders::class)($user, CarbonImmutable::now());
    expect(sentLines($user))->toBe([]);

    Carbon::setTestNow($due);
    app(SendDueReminders::class)($user, CarbonImmutable::now());
    expect(sentLines($user))->toBe(['Weekly review available | Your week is ready to review | /today?date=2026-09-19'])
        ->and(ReminderMorning::query()->where('user_id', $user->id)->get()->map(fn (ReminderMorning $morning) => $morning->date->format('Y-m-d'))->all())
        ->toBe(['2026-09-19']);
})->with([
    'Australia/Brisbane' => ['Australia/Brisbane', '2026-09-18 23:00:00', '2026-09-18 13:00:00'],
    'UTC' => ['UTC', '2026-09-19 09:00:00', '2026-09-18 23:00:00'],
]);

it('SET-012 a meal moved earlier, inside the window, after its reminder went out is not reminded twice', function (string $zone, string $first, string $second) {
    $user = remindedUser();
    $user->forceFill(['time_zone' => $zone])->save();
    NotificationPref::query()->create(['user_id' => $user->id, 'kind' => 'meal', 'on' => true]);

    Carbon::setTestNow($first);
    app(SendDueReminders::class)($user, CarbonImmutable::now());
    $day = DayTarget::query()->where('user_id', $user->id)->whereDate('date', '2026-09-24')->sole();
    $meals = $day->meals;
    $meals[1]['time'] = '12:25';
    $day->update(['meals' => $meals]);
    Carbon::setTestNow($second);
    app(SendDueReminders::class)($user, CarbonImmutable::now());

    expect(sentLines($user))->toBe(['Lunch | Time to eat | /today?date=2026-09-24'])
        ->and(SentReminder::query()->where('user_id', $user->id)->sole()->sent_at->format('Y-m-d H:i:s'))
        ->toBe('2026-09-24 12:30:00');
})->with([
    'Australia/Brisbane' => ['Australia/Brisbane', '2026-09-24 02:30:00', '2026-09-24 02:32:00'],
    'UTC' => ['UTC', '2026-09-24 12:30:00', '2026-09-24 12:32:00'],
]);

it('SET-012 reminders:send queues each user at their local due time', function () {
    $brisbane = remindedUser();
    $brisbane->forceFill(['time_zone' => 'Australia/Brisbane'])->save();
    $utc = remindedUser();
    Queue::fake();

    Carbon::setTestNow('2026-09-25 21:00:00');
    $this->artisan('reminders:send')->assertSuccessful();
    Queue::assertPushed(SendUserReminders::class, 1);
    Queue::assertPushed(SendUserReminders::class, fn (SendUserReminders $job) => $job->user->is($brisbane)
        && $job->now->format('Y-m-d H:i:s') === '2026-09-25 21:00:00');

    Queue::fake();
    Carbon::setTestNow('2026-09-26 07:00:00');
    $this->artisan('reminders:send')->assertSuccessful();
    Queue::assertPushed(SendUserReminders::class, 1);
    Queue::assertPushed(SendUserReminders::class, fn (SendUserReminders $job) => $job->user->is($utc));

    // 13:00 in Brisbane and 03:00 in UTC: nothing is due for either.
    Queue::fake();
    Carbon::setTestNow('2026-09-26 03:00:00');
    DB::enableQueryLog();
    $this->artisan('reminders:send')->assertSuccessful();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    DB::flushQueryLog();
    Queue::assertNothingPushed();
    expect($queries)->toBeLessThanOrEqual(10);
});

it('SET-012 a pass just after the user\'s midnight still sends what fell due late their evening before', function () {
    $user = remindedUser();
    $user->forceFill(['time_zone' => 'America/Los_Angeles'])->save();
    WeighInScheduleEntry::query()->where('user_id', $user->id)->update(['time' => '23:55']);

    // 00:02 on Sunday 27 in Los Angeles, when the UTC day has long been the 27th.
    Carbon::setTestNow('2026-09-27 07:02:00');
    app(SendDueReminders::class)($user, CarbonImmutable::now());

    expect(sentLines($user))->toBe(['Weigh-in | Time to weigh in | /today?date=2026-09-26&open=weigh-in']);
});

it('SET-012 the window is the user\'s last 10 minutes, so on the day the clocks go forward a reminder in the skipped hour is not sent', function () {
    $user = remindedUser();
    $user->forceFill(['time_zone' => 'Australia/Sydney'])->save();
    WeighInScheduleEntry::query()->where('user_id', $user->id)->update(['weekdays' => [7], 'time' => '02:30']);

    // Sunday 4 Oct in Sydney: 02:00 becomes 03:00 at 16:00 UTC on the 3rd.
    $passes = fn (string $from) => collect(range(0, 20))->each(function (int $minute) use ($user, $from) {
        Carbon::setTestNow(CarbonImmutable::parse($from)->addMinutes($minute));
        app(SendDueReminders::class)($user, CarbonImmutable::now());
    });
    $passes('2026-10-03 15:50:00');
    expect(sentLines($user))->toBe([]);

    WeighInScheduleEntry::query()->where('user_id', $user->id)->update(['time' => '03:05']);
    $passes('2026-10-03 15:50:00');
    expect(sentLines($user))->toBe(['Weigh-in | Time to weigh in | /today?date=2026-10-04&open=weigh-in']);
});
