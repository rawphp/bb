<?php

use App\Actions\Today\MoveWorkout;
use App\Actions\Today\PlannedSession;
use App\Actions\Today\RetargetableDay;
use App\Actions\Today\SetWorkoutTime;
use App\Capabilities\Today\Data\WorkoutMoveInput;
use App\Capabilities\Today\Data\WorkoutTimeSetInput;
use App\Models\DayTarget;
use App\Models\Mesocycle;
use App\Models\Profile;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Today\DayPlans;
use App\Support\Today\TodaySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SheetSeed;
use Tests\Support\TrainSeed;

// What workout_move and workout_time_set save. The re-target rule is RetargetTest's. Meso 2 trains Upper A (day 1) on
// Monday and Lower A (day 2) on Thursday at 5:30 PM for 60 minutes; the plan week of week 3 runs Monday 21 to Sunday
// 27 September 2026.
uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/** @return array{User, Mesocycle} */
function sessionWorld(string $today = '2026-09-22'): array
{
    Carbon::setTestNow("{$today} 08:00:00");
    $user = TrainSeed::user();

    return [$user, TrainSeed::mesocycle($user)];
}

function moveSession(User $user, Mesocycle $mesocycle, string $date, int $day = 2, int $week = 3): array
{
    return app(MoveWorkout::class)($user, new WorkoutMoveInput($mesocycle->id, $week, $day, $date));
}

function timeSession(User $user, Mesocycle $mesocycle, string $time, int $day = 2, int $week = 3): array
{
    return app(SetWorkoutTime::class)($user, new WorkoutTimeSetInput($mesocycle->id, $week, $day, $time));
}

/** @return array<string, mixed> each seeded day's stored state, to show nothing was saved */
function storedDays(): array
{
    return DayTarget::query()->orderBy('date')->get()->map(fn (DayTarget $day) => $day->only(['day_type', 'kcal', 'carbs_g', 'meals']))->all();
}

// MARK: workout_move

it('SCH-038 SCH-032 moves a planned session to a free day of its plan week at its clock time, and re-targets both days', function () {
    [$user, $mesocycle] = sessionWorld();
    $thursday = SheetSeed::day($user, '2026-09-24');
    $saturday = SheetSeed::day($user, '2026-09-26', ['day_type' => 'rest', 'kcal' => 2200.0, 'carbs_g' => 220.0]);

    $moved = moveSession($user, $mesocycle, '2026-09-26');

    $row = WorkoutSession::query()->where('mesocycle_id', $mesocycle->id)->sole();
    $snapshot = TodaySnapshot::load($user, '2026-09-26');
    expect($moved['toast'])->toBe('Meals updated for your 5:30 PM workout')
        ->and($moved['dates'])->toBe(['2026-09-24', '2026-09-26'])
        ->and($moved['week'])->toBe(['2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25', '2026-09-26', '2026-09-27'])
        ->and([$row->week, $row->day, $row->is_skipped])->toBe([3, 2, false])
        ->and($row->planned_start_at->format('Y-m-d H:i'))->toBe('2026-09-26 17:30')
        ->and($row->planned_end_at->format('Y-m-d H:i'))->toBe('2026-09-26 18:30')
        // The source day becomes a rest day; the destination takes the session's energy.
        ->and($thursday->fresh()->day_type->value)->toBe('rest')
        ->and($saturday->fresh()->day_type->value)->not->toBe('rest')
        ->and($saturday->fresh()->kcal)->toBeGreaterThan(2200.0)
        ->and(collect($saturday->fresh()->meals)->sum('kcal'))->toEqualWithDelta($saturday->fresh()->kcal, 0.5)
        // Today reads the session on its new day.
        ->and($snapshot->workout(DayPlans::day('2026-09-26'))?->day)->toBe(2)
        ->and($snapshot->workout(DayPlans::day('2026-09-24')))->toBeNull();
});

it('SCH-038 refuses a move onto a day that already holds a session, and saves nothing', function () {
    [$user, $mesocycle] = sessionWorld('2026-09-21');
    SheetSeed::day($user, '2026-09-21');
    SheetSeed::day($user, '2026-09-24');
    $before = storedDays();

    expect(fn () => moveSession($user, $mesocycle, '2026-09-24', day: 1))->toThrow(DomainException::class, 'That day already has a workout. Pick another day.')
        ->and(WorkoutSession::query()->count())->toBe(0)
        ->and(storedDays())->toEqual($before);
});

it('SCH-038 refuses a destination the move does not offer', function (string $date, string $message) {
    [$user, $mesocycle] = sessionWorld();
    foreach (array_unique(['2026-09-24', $date]) as $seeded) {
        SheetSeed::day($user, $seeded);
    }
    $before = storedDays();

    expect(fn () => moveSession($user, $mesocycle, $date))->toThrow(DomainException::class, $message)
        ->and(WorkoutSession::query()->count())->toBe(0)
        ->and(storedDays())->toEqual($before);
})->with([
    'a day of the next plan week' => ['2026-09-28', 'A workout can only move within its plan week. Pick another day.'],
    'a past day of its week' => ['2026-09-21', RetargetableDay::PAST],
    'its own day' => ['2026-09-24', MoveWorkout::SAME_DAY],
]);

it('SCH-038 refuses to move a session that has started, is skipped, or whose day has passed', function (array $row, int $day, string $message) {
    [$user, $mesocycle] = sessionWorld();
    SheetSeed::day($user, '2026-09-24');
    SheetSeed::day($user, '2026-09-26');
    if ($row !== []) {
        WorkoutSession::factory()->for($user)->create(['mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => $day,
            'planned_start_at' => '2026-09-24 17:30:00', 'planned_end_at' => '2026-09-24 18:30:00'] + $row);
    }
    $before = storedDays();

    expect(fn () => moveSession($user, $mesocycle, '2026-09-26', day: $day))->toThrow(DomainException::class, $message)
        ->and(storedDays())->toEqual($before);
})->with([
    'started' => [['actual_start_at' => '2026-09-22 07:00:00', 'is_skipped' => false], 2, PlannedSession::LOCKED],
    'skipped' => [['is_skipped' => true], 2, PlannedSession::LOCKED],
    'on a past day' => [[], 1, PlannedSession::LOCKED],
]);

it('SCH-038 refuses a session the plan does not hold, and a day with no targets', function () {
    [$user, $mesocycle] = sessionWorld();
    SheetSeed::day($user, '2026-09-24');

    expect(fn () => moveSession($user, $mesocycle, '2026-09-26', week: 9))->toThrow(DomainException::class, PlannedSession::NOT_FOUND)
        ->and(fn () => moveSession($user, $mesocycle, '2026-09-26'))->toThrow(DomainException::class, RetargetableDay::NO_MEALS)
        ->and(WorkoutSession::query()->count())->toBe(0);
});

it('SCH-038 a session moved before moves again under the same row', function () {
    [$user, $mesocycle] = sessionWorld();
    SheetSeed::day($user, '2026-09-25');
    SheetSeed::day($user, '2026-09-26');
    $row = WorkoutSession::factory()->for($user)->create([
        'mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 2, 'is_skipped' => false,
        'planned_start_at' => '2026-09-25 17:30:00', 'planned_end_at' => '2026-09-25 18:30:00',
    ]);

    $moved = moveSession($user, $mesocycle, '2026-09-26');

    expect($moved['dates'])->toBe(['2026-09-25', '2026-09-26'])
        ->and(WorkoutSession::query()->sole()->id)->toBe($row->id)
        ->and(WorkoutSession::query()->sole()->planned_start_at->format('Y-m-d H:i'))->toBe('2026-09-26 17:30');
});

it('SCH-055 a checked-in meal on the destination keeps its time and target', function () {
    [$user, $mesocycle] = sessionWorld();
    SheetSeed::day($user, '2026-09-24');
    $saturday = SheetSeed::day($user, '2026-09-26', ['day_type' => 'rest']);
    $lunch = SheetSeed::id(SheetSeed::LUNCH, '260926');
    SheetSeed::checkIn($lunch);
    $before = collect($saturday->meals)->firstWhere('id', $lunch);

    moveSession($user, $mesocycle, '2026-09-26');

    expect(collect($saturday->fresh()->meals)->firstWhere('id', $lunch))->toEqual($before);
});

it('SCH-038 a refusal of the re-target rule saves nothing', function () {
    [$user, $mesocycle] = sessionWorld();
    SheetSeed::day($user, '2026-09-24');
    SheetSeed::day($user, '2026-09-26');
    // A one-hour window cannot hold three meals around a workout.
    Profile::query()->where('user_id', $user->id)->update(['eating_window' => ['start' => '17:00', 'end' => '18:00']]);
    $before = storedDays();

    expect(fn () => moveSession($user, $mesocycle, '2026-09-26'))->toThrow(DomainException::class, 'Your meals can\'t fit around the workout at that time. Nothing was changed.')
        ->and(WorkoutSession::query()->count())->toBe(0)
        ->and(storedDays())->toEqual($before);
});

// MARK: workout_time_set

it('SCH-056 sets a planned session\'s start, saves its row until its length, and re-targets its day', function () {
    [$user, $mesocycle] = sessionWorld();
    $thursday = SheetSeed::day($user, '2026-09-24');
    $times = collect($thursday->meals)->pluck('time')->all();

    $timed = timeSession($user, $mesocycle, '12:00');

    $row = WorkoutSession::query()->where('mesocycle_id', $mesocycle->id)->sole();
    expect($timed['toast'])->toBe('Meals updated for your 12:00 PM workout')
        ->and($timed['dates'])->toBe(['2026-09-24'])
        ->and($timed['week'])->toHaveCount(7)
        ->and($row->planned_start_at->format('Y-m-d H:i'))->toBe('2026-09-24 12:00')
        ->and($row->planned_end_at->format('Y-m-d H:i'))->toBe('2026-09-24 13:00')
        ->and(collect($thursday->fresh()->meals)->pluck('time')->all())->not->toBe($times)
        ->and(collect($thursday->fresh()->meals)->sum('kcal'))->toEqualWithDelta($thursday->fresh()->kcal, 0.5)
        ->and(TodaySnapshot::load($user, '2026-09-24')->workout(DayPlans::day('2026-09-24'))?->time->shortText())->toBe('12:00 PM');
});

it('SCH-056 a start the session already has changes nothing', function () {
    [$user, $mesocycle] = sessionWorld();
    SheetSeed::day($user, '2026-09-24');
    $before = storedDays();

    expect(timeSession($user, $mesocycle, '17:30')['toast'])->toBe('Your workout is already at 5:30 PM')
        ->and(WorkoutSession::query()->count())->toBe(0)
        ->and(storedDays())->toEqual($before);
});

it('SCH-055 a checked-in meal keeps its time and target when the start changes', function () {
    [$user, $mesocycle] = sessionWorld();
    $thursday = SheetSeed::day($user, '2026-09-24');
    $breakfast = SheetSeed::id(SheetSeed::BREAKFAST, '260924');
    SheetSeed::checkIn($breakfast);
    $before = collect($thursday->meals)->firstWhere('id', $breakfast);

    timeSession($user, $mesocycle, '12:00');

    expect(collect($thursday->fresh()->meals)->firstWhere('id', $breakfast))->toEqual($before);
});

it('SCH-056 refuses a new start for a session that has started, is skipped, or whose day has passed', function (array $row, int $day) {
    [$user, $mesocycle] = sessionWorld();
    SheetSeed::day($user, '2026-09-24');
    if ($row !== []) {
        WorkoutSession::factory()->for($user)->create(['mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => $day,
            'planned_start_at' => '2026-09-24 17:30:00', 'planned_end_at' => '2026-09-24 18:30:00'] + $row);
    }
    $rows = WorkoutSession::query()->get()->toArray();
    $before = storedDays();

    expect(fn () => timeSession($user, $mesocycle, '12:00', day: $day))->toThrow(DomainException::class, PlannedSession::LOCKED)
        ->and(WorkoutSession::query()->get()->toArray())->toEqual($rows)
        ->and(storedDays())->toEqual($before);
})->with([
    'started' => [['actual_start_at' => '2026-09-22 07:00:00', 'is_skipped' => false], 2],
    'skipped' => [['is_skipped' => true], 2],
    'on a past day' => [[], 1],
]);

it('SCH-038 SCH-056 a move or a new start named by the mesocycle id in capitals saves the row under the stored id', function (string $write, string $date, string $time) {
    [$user, $mesocycle] = sessionWorld();
    SheetSeed::day($user, '2026-09-24');
    SheetSeed::day($user, '2026-09-26');
    $id = strtoupper($mesocycle->id);

    $write === 'move'
        ? app(MoveWorkout::class)($user, new WorkoutMoveInput($id, 3, 2, $date))
        : app(SetWorkoutTime::class)($user, new WorkoutTimeSetInput($id, 3, 2, '12:00'));

    $workout = TodaySnapshot::load($user, $date)->workout(DayPlans::day($date));
    expect(WorkoutSession::query()->sole()->mesocycle_id)->toBe($mesocycle->id)
        ->and([$workout?->day, $workout?->time->shortText()])->toBe([2, $time]);
})->with([
    'move' => ['move', '2026-09-26', '5:30 PM'],
    'time' => ['time', '2026-09-24', '12:00 PM'],
]);

it('SCH-038 refuses a move onto a day whose session is skipped: a skipped session still holds its day', function () {
    [$user, $mesocycle] = sessionWorld('2026-09-21');
    SheetSeed::day($user, '2026-09-21');
    SheetSeed::day($user, '2026-09-24');
    WorkoutSession::factory()->for($user)->create([
        'mesocycle_id' => $mesocycle->id, 'week' => 3, 'day' => 2, 'is_skipped' => true,
        'planned_start_at' => '2026-09-24 17:30:00', 'planned_end_at' => '2026-09-24 18:30:00',
    ]);
    $rows = WorkoutSession::query()->get()->toArray();
    $before = storedDays();

    expect(fn () => moveSession($user, $mesocycle, '2026-09-24', day: 1))->toThrow(DomainException::class, 'That day already has a workout. Pick another day.')
        ->and(WorkoutSession::query()->get()->toArray())->toEqual($rows)
        ->and(storedDays())->toEqual($before);
});

it('SCH-038 SCH-056 a move or a new start whose session row fails to save leaves the days as they were', function (string $write) {
    [$user, $mesocycle] = sessionWorld();
    SheetSeed::day($user, '2026-09-24');
    SheetSeed::day($user, '2026-09-26');
    $before = storedDays();
    WorkoutSession::saving(fn (): never => throw new RuntimeException('the session could not be saved'));

    expect(fn () => $write === 'move' ? moveSession($user, $mesocycle, '2026-09-26') : timeSession($user, $mesocycle, '12:00'))
        ->toThrow(RuntimeException::class, 'the session could not be saved')
        ->and(storedDays())->toEqual($before);
})->with(['move', 'time']);

it('SCH-056 a start whose meals cannot fit around it is refused in plain words and saves nothing', function () {
    [$user, $mesocycle] = sessionWorld();
    SheetSeed::day($user, '2026-09-24');
    Profile::query()->where('user_id', $user->id)->update(['eating_window' => ['start' => '17:00', 'end' => '18:00']]);
    $before = storedDays();

    expect(fn () => timeSession($user, $mesocycle, '12:00'))->toThrow(DomainException::class, 'Your meals can\'t fit around the workout at that time. Nothing was changed.')
        ->and(WorkoutSession::query()->count())->toBe(0)
        ->and(storedDays())->toEqual($before);
});
