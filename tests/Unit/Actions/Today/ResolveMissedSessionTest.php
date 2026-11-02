<?php

use App\Actions\Today\ResolveMissedSession;
use App\Capabilities\Today\Data\MissedSessionResolveInput;
use App\Models\DayTarget;
use App\Models\Mesocycle;
use App\Models\PlanWeek;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Today\RetargetDays;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Support\SheetSeed;
use Tests\Support\TrainSeed;

// What missed_session_resolve saves (SCH-055 to SCH-057). The options are RetargetTest's (missedDayOptions); the
// re-target rule is RetargetTest's too. Meso 2 trains Upper A on Monday and Lower A on Thursday at 5:30 PM; Monday 21
// September (week 3, day 1) passed with nothing logged. The plan week runs Monday 21 to Sunday 27.
uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/** @return array{User, Mesocycle} */
function missedWorld(string $today): array
{
    Carbon::setTestNow("{$today} 08:00:00");
    $user = TrainSeed::user();

    return [$user, TrainSeed::mesocycle($user)];
}

function resolveMissed(User $user, Mesocycle $mesocycle, string $choice, ?string $date = null, int $day = 1, int $week = 3): array
{
    return app(ResolveMissedSession::class)($user, new MissedSessionResolveInput($mesocycle->id, $week, $day, $choice, $date));
}

it('SCH-057 SCH-050 SCH-056 "Do it today" moves the session to today at its time and re-targets today around it', function () {
    [$user, $mesocycle] = missedWorld('2026-09-22');
    $day = SheetSeed::day($user, '2026-09-22', ['day_type' => 'rest', 'kcal' => 2200.0, 'carbs_g' => 220.0]);

    $resolved = resolveMissed($user, $mesocycle, 'do_it_today');

    $row = WorkoutSession::query()->where('mesocycle_id', $mesocycle->id)->sole();
    $meals = collect($day->fresh()->meals);
    expect($resolved['toast'])->toBe('Meals updated for your 5:30 PM workout')
        ->and($resolved['dates'])->toBe(['2026-09-21', '2026-09-22'])
        ->and($resolved['week'])->toBe(['2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25', '2026-09-26', '2026-09-27'])
        ->and([$row->week, $row->day, $row->is_skipped])->toBe([3, 1, false])
        ->and($row->planned_start_at->format('Y-m-d H:i'))->toBe('2026-09-22 17:30')
        ->and($row->planned_end_at->format('Y-m-d H:i'))->toBe('2026-09-22 18:30')
        ->and($day->fresh()->day_type->value)->not->toBe('rest')
        ->and($day->fresh()->kcal)->toBeGreaterThan(2200.0)
        ->and($day->fresh()->protein_g)->toBe(170.0)
        // The meals are placed again around 5:30 to 6:30 PM and split from the new total; ids, names and order hold.
        ->and($meals->pluck('id')->take(3)->all())->toBe([
            SheetSeed::id(SheetSeed::BREAKFAST, '260922'), SheetSeed::id(SheetSeed::LUNCH, '260922'), SheetSeed::id(SheetSeed::DINNER, '260922'),
        ])
        ->and($meals->pluck('name')->take(3)->all())->toBe(['Breakfast', 'Lunch', 'Dinner'])
        ->and($meals->pluck('time')->all())->not->toBe(['07:30', '12:30', '19:00'])
        ->and($meals->sum('kcal'))->toEqualWithDelta($day->fresh()->kcal, 0.5);
});

it('SCH-055 a meal whose planned foods are all locked keeps its target when today is re-targeted', function () {
    [$user, $mesocycle] = missedWorld('2026-09-22');
    $day = SheetSeed::day($user, '2026-09-22', ['day_type' => 'rest']);
    $dinner = SheetSeed::id(SheetSeed::DINNER, '260922');
    $lunch = SheetSeed::id(SheetSeed::LUNCH, '260922');
    SheetSeed::food($dinner, locked: true);
    // Lunch has a locked food and an unlocked one, so it can still be re-fitted.
    SheetSeed::food($lunch, locked: true);
    SheetSeed::food($lunch);
    $before = collect($day->meals)->keyBy('id');

    resolveMissed($user, $mesocycle, 'do_it_today', '2026-09-22');

    $after = collect($day->fresh()->meals)->keyBy('id');
    expect($after[$dinner])->toEqual($before[$dinner])
        ->and($after[$lunch]['kcal'])->not->toEqual($before[$lunch]['kcal']);
});

it('SCH-057 a session moved earlier keeps its row when it is answered', function () {
    [$user, $mesocycle] = missedWorld('2026-09-22');
    $moved = WorkoutSession::factory()->for($user)->create([
        'mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 1, 'planned_start_at' => '2026-09-21 06:00:00', 'planned_end_at' => '2026-09-21 07:00:00',
    ]);

    resolveMissed($user, $mesocycle, 'skip');

    expect(WorkoutSession::query()->sole()->only(['id', 'is_skipped']))->toBe(['id' => $moved->id, 'is_skipped' => true]);
});

it('SCH-057 refuses "Do it today" sent for a day that is no longer today, and saves nothing', function () {
    [$user, $mesocycle] = missedWorld('2026-09-23');
    SheetSeed::day($user, '2026-09-23', ['day_type' => 'rest']);

    expect(fn () => resolveMissed($user, $mesocycle, 'do_it_today', '2026-09-22'))->toThrow(DomainException::class, 'That day has changed. Open the prompt again.')
        ->and(WorkoutSession::query()->count())->toBe(0);
});

it('SCH-057 a move to a day with no targets is refused in plain words and saves nothing', function () {
    [$user, $mesocycle] = missedWorld('2026-09-22');

    expect(fn () => resolveMissed($user, $mesocycle, 'do_it_today', '2026-09-22'))->toThrow(DomainException::class, 'Couldn\'t update this day\'s meals. Nothing was changed.')
        ->and(WorkoutSession::query()->count())->toBe(0);
});

it('SCH-055 a checked-in meal keeps its time and target when today is re-targeted', function () {
    [$user, $mesocycle] = missedWorld('2026-09-22');
    $day = SheetSeed::day($user, '2026-09-22', ['day_type' => 'rest']);
    $breakfast = SheetSeed::id(SheetSeed::BREAKFAST, '260922');
    SheetSeed::checkIn($breakfast);
    $before = collect($day->meals)->firstWhere('id', $breakfast);

    resolveMissed($user, $mesocycle, 'do_it_today');

    expect(collect($day->fresh()->meals)->firstWhere('id', $breakfast))->toEqual($before);
});

it('SCH-057 "Do it on the next rest day" moves the session to the next rest day of the plan week', function () {
    [$user, $mesocycle] = missedWorld('2026-09-24');
    SheetSeed::day($user, '2026-09-25', ['day_type' => 'rest']);

    resolveMissed($user, $mesocycle, 'next_rest_day', '2026-09-25');

    expect(WorkoutSession::query()->sole()->planned_start_at->format('Y-m-d H:i'))->toBe('2026-09-25 17:30');
});

it('SCH-057 "Skip it" saves the session skipped and leaves the past day\'s targets as they were', function () {
    [$user, $mesocycle] = missedWorld('2026-09-22');
    $missed = SheetSeed::day($user, '2026-09-21');
    $before = $missed->fresh()->getAttributes();

    $resolved = resolveMissed($user, $mesocycle, 'skip');

    expect([$resolved['toast'], $resolved['dates']])->toBe(['Session skipped', ['2026-09-21']]);

    $row = WorkoutSession::query()->sole();
    expect($row->is_skipped)->toBeTrue()
        ->and($row->planned_start_at->format('Y-m-d H:i'))->toBe('2026-09-21 17:30')
        ->and($missed->fresh()->getAttributes())->toBe($before);
});

it('SCH-057 refuses a choice the prompt would not offer, and saves nothing', function (string $today, string $choice, ?string $date, string $message) {
    [$user, $mesocycle] = missedWorld($today);
    SheetSeed::day($user, $today);

    expect(fn () => resolveMissed($user, $mesocycle, $choice, $date))->toThrow(DomainException::class, $message)
        ->and(WorkoutSession::query()->count())->toBe(0)
        ->and(DayTarget::query()->sole()->kcal)->toBe(2600.0);
})->with([
    'today on a training day' => ['2026-09-24', 'do_it_today', '2026-09-24', 'Today is a training day'],
    'a rest day that is not the next one' => ['2026-09-24', 'next_rest_day', '2026-09-26', 'That day has changed'],
    'a next rest day when today is a rest day' => ['2026-09-22', 'next_rest_day', '2026-09-23', 'No rest day is left'],
]);

it('SCH-057 refuses a session that is not missed: not yet due, due today, already logged, or in another week', function (string $today, Closure $arrange, int $week, int $day) {
    [$user, $mesocycle] = missedWorld($today);
    $arrange($mesocycle);

    expect(fn () => resolveMissed($user, $mesocycle, 'skip', day: $day, week: $week))->toThrow(DomainException::class, ResolveMissedSession::NOT_MISSED);
})->with([
    'not yet due' => ['2026-09-22', fn () => null, 3, 2],
    'due today' => ['2026-09-21', fn () => null, 3, 1],
    'started' => ['2026-09-22', fn (Mesocycle $mesocycle) => WorkoutSession::factory()->for($mesocycle->user)->create([
        'mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 1, 'actual_start_at' => '2026-09-21 17:30:00',
    ]), 3, 1],
    'skipped' => ['2026-09-22', fn (Mesocycle $mesocycle) => WorkoutSession::factory()->for($mesocycle->user)->create([
        'mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 1, 'is_skipped' => true,
    ]), 3, 1],
    'another mesocycle week' => ['2026-09-22', fn () => null, 2, 1],
]);

it('SCH-041 the re-target keeps meals out of a busy period on the day it moves to, and ignores one on other weekdays', function () {
    $times = function (array $weekdays): array {
        [$user, $mesocycle] = missedWorld('2026-09-22');
        $user->profile->update(['busy_periods' => [['label' => 'School run', 'start' => '15:00', 'end' => '16:30', 'weekdays' => $weekdays]]]);
        $day = SheetSeed::day($user, '2026-09-22', ['day_type' => 'rest']);
        resolveMissed($user, $mesocycle, 'do_it_today', '2026-09-22');

        $times = array_column($day->fresh()->meals, 'time');
        $day->forceDelete(); // meal ids are unique across users, and the next run seeds the same ones

        return $times;
    };

    // Without the period the pre-workout meal sits at 4 PM, inside it.
    expect($times([3]))->toBe(['07:00', '16:00', '18:30'])
        ->and(array_filter($times([2]), fn (string $time) => $time >= '15:00' && $time < '16:30'))->toBe([]);
});

it('SCH-060 an away week on rest-day calories leaves the workout\'s energy out of the day it moves to', function () {
    $kcal = function (string $calories): float {
        [$user, $mesocycle] = missedWorld('2026-09-22');
        PlanWeek::factory()->for($user)->create(['start_date' => '2026-09-21', 'is_away' => true, 'away_training' => 'deload', 'away_calories' => $calories]);
        $day = SheetSeed::day($user, '2026-09-22', ['day_type' => 'rest']);
        resolveMissed($user, $mesocycle, 'do_it_today', '2026-09-22');

        $kcal = $day->fresh()->kcal;
        $day->forceDelete(); // meal ids are unique across users, and the next run seeds the same ones

        return $kcal;
    };

    expect($kcal('rest_days'))->toBeLessThan($kcal('maintenance'));
});

it('SCH-057 a move is refused in plain words when the eating window can\'t be read, and saves nothing', function (array $profile) {
    [$user, $mesocycle] = missedWorld('2026-09-22');
    $user->profile->update($profile);
    $day = SheetSeed::day($user, '2026-09-22', ['day_type' => 'rest']);
    $before = $day->fresh()->getAttributes();

    expect(fn () => resolveMissed($user, $mesocycle, 'do_it_today', '2026-09-22'))->toThrow(DomainException::class, RetargetDays::CANNOT)
        ->and(WorkoutSession::query()->count())->toBe(0)
        ->and($day->fresh()->getAttributes())->toBe($before);
})->with([
    'an empty eating window' => [['eating_window' => []]],
    'a window start that is not a time' => [['eating_window' => ['start' => 'late', 'end' => '20:00']]],
]);

it('SCH-056 the day and the session row are saved together: a failed row save leaves the day as it was', function () {
    [$user, $mesocycle] = missedWorld('2026-09-22');
    $day = SheetSeed::day($user, '2026-09-22', ['day_type' => 'rest']);
    $before = $day->fresh()->getAttributes();
    $armed = true;
    // Left registered and disarmed after: Event::forget would also drop the model's own listeners.
    Event::listen('eloquent.saving: '.WorkoutSession::class, function () use (&$armed) {
        if ($armed) {
            throw new RuntimeException('row save failed');
        }
    });

    try {
        expect(fn () => resolveMissed($user, $mesocycle, 'do_it_today', '2026-09-22'))->toThrow(RuntimeException::class, 'row save failed');
    } finally {
        $armed = false;
    }

    expect($day->fresh()->getAttributes())->toBe($before);
});

it('SCH-057 an answer named by the mesocycle id in capitals saves the row under the stored id', function (string $choice) {
    [$user, $mesocycle] = missedWorld('2026-09-22');
    SheetSeed::day($user, '2026-09-22', ['day_type' => 'rest']);

    app(ResolveMissedSession::class)($user, new MissedSessionResolveInput(strtoupper($mesocycle->id), 3, 1, $choice, $choice === 'skip' ? null : '2026-09-22'));

    expect(WorkoutSession::query()->sole()->mesocycle_id)->toBe($mesocycle->id);
})->with(['do_it_today', 'skip']);
