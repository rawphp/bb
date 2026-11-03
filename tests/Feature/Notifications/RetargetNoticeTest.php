<?php

use App\Actions\Notifications\NotifyRetarget;
use App\Domain\Model\Day;
use App\Domain\Model\DayType;
use App\Domain\Model\Meal;
use App\Domain\Model\MealTarget;
use App\Domain\Placement\ClockTime;
use App\Domain\Retarget\RetargetDay;
use App\Domain\Retarget\RetargetMeal;
use App\Domain\WeekBuilder\WeekMealKind;
use App\Models\DayTarget;
use App\Models\NotificationPref;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\PushNotification;
use App\Support\Today\RetargetDays;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Tests\Support\TodaySeed;

// NOTIF-006: every re-target is saved through RetargetDays::save (SCH-050 to SCH-054), which sends the notice when it
// changed a meal the user planned foods for. Wednesday 2026-09-23 of TodaySeed has Oats planned for breakfast, a
// Chili recipe for dinner and nothing planned for lunch.

beforeEach(function () {
    config(['webpush.vapid.public_key' => 'public-key', 'webpush.vapid.private_key' => 'private-key']);
    Notification::fake();
    Carbon::setTestNow('2026-09-23 10:00:00');
});
afterEach(fn () => Carbon::setTestNow());

function retargetUser(bool $subscribed = true): User
{
    $user = TodaySeed::user();
    TodaySeed::targets($user);
    TodaySeed::wednesday($user, TodaySeed::mesocycle($user));
    if ($subscribed) {
        PushSubscription::factory()->for($user)->create();
    }

    return $user;
}

/** Saves Wednesday re-targeted, with `$kcal` added to each named meal. */
function retargetWednesday(User $user, array $kcal): void
{
    $row = DayTarget::query()->where('user_id', $user->id)->whereDate('date', '2026-09-23')->sole();
    $meals = array_map(fn (array $meal) => new RetargetMeal(
        meal: new Meal($meal['id'], $meal['name'], new MealTarget($meal['kcal'] + ($kcal[$meal['name']] ?? 0), $meal['protein_g'], $meal['fat_g'], $meal['carbs_g'])),
        time: ClockTime::fromHhmm($meal['time']),
        kind: WeekMealKind::Regular,
    ), $row->meals);

    RetargetDays::save($row, new RetargetDay(new Day(new DateTimeImmutable('2026-09-23'), DayType::Rest), null, null, $meals, new MealTarget(2600, 170, 70, 320)));
}

function noticeLines(User $user): array
{
    $lines = [];
    foreach (Notification::sent($user, PushNotification::class) as $notification) {
        $lines[] = "{$notification->title} | {$notification->body} | {$notification->url}";
    }

    return $lines;
}

it('NOTIF-006 a re-target that changes meals with planned foods sends one notice naming them', function () {
    $user = retargetUser();

    retargetWednesday($user, ['Breakfast' => 120, 'Lunch' => 50, 'Dinner' => -80]);

    expect(noticeLines($user))->toBe(['Meal targets changed | New targets for Breakfast, Dinner. Check their planned foods. | /today?date=2026-09-23']);
});

it('NOTIF-006 a re-target that changes only meals without planned foods sends nothing', function () {
    $user = retargetUser();

    retargetWednesday($user, ['Lunch' => 50]);

    Notification::assertNothingSent();
});

it('NOTIF-006 NOTIF-007 sends nothing with the switch off', function () {
    $user = retargetUser();
    NotificationPref::query()->create(['user_id' => $user->id, 'kind' => 'retarget', 'on' => false]);

    retargetWednesday($user, ['Breakfast' => 120]);

    Notification::assertNothingSent();
});

it('NOTIF-006 sends nothing to a user with no subscribed browser', function () {
    $user = retargetUser(subscribed: false);

    retargetWednesday($user, ['Breakfast' => 120]);

    Notification::assertNothingSent();
});

it('NOTIF-006 a re-target that rolls back sends nothing', function () {
    $user = retargetUser();

    try {
        DB::transaction(function () use ($user) {
            retargetWednesday($user, ['Breakfast' => 120]);
            throw new RuntimeException('the rest of the write failed');
        });
    } catch (RuntimeException) {
    }

    Notification::assertNothingSent();
    expect(DayTarget::query()->where('user_id', $user->id)->whereDate('date', '2026-09-23')->sole()->meals[0]['kcal'])->toEqual(780);
});

it('NOTIF-006 a notice that fails is reported and never fails the re-target it follows', function () {
    $user = retargetUser();
    Exceptions::fake();
    app()->instance(NotifyRetarget::class, new class
    {
        public function __invoke(): void
        {
            throw new RuntimeException('push store down');
        }
    });

    retargetWednesday($user, ['Breakfast' => 120]);

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'push store down');
    expect(DayTarget::query()->where('user_id', $user->id)->whereDate('date', '2026-09-23')->sole()->meals[0]['kcal'])->toEqual(900);
});

it('SET-012 the re-target notice is stamped with the user\'s clock, as every reminder is', function (string $zone, string $fireAt) {
    $user = retargetUser();
    $user->forceFill(['time_zone' => $zone])->save();
    $row = DayTarget::query()->where('user_id', $user->id)->whereDate('date', '2026-09-23')->sole();
    $before = $row->meals;
    $meals = $before;
    $meals[0]['kcal'] += 120;
    $row->meals = $meals;

    // 10:00 UTC is 20:00 in Brisbane.
    expect(app(NotifyRetarget::class)->notice($row, $before)?->fireAt->format('Y-m-d H:i'))->toBe($fireAt);
})->with([
    'Australia/Brisbane' => ['Australia/Brisbane', '2026-09-23 20:00'],
    'UTC' => ['UTC', '2026-09-23 10:00'],
]);
