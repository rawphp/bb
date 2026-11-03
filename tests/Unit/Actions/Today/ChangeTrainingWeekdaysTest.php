<?php

use App\Actions\Today\ChangeTrainingWeekdays;
use App\Actions\Today\PlannedSession;
use App\Capabilities\Support\FieldRefused;
use App\Capabilities\Today\Data\TrainingWeekdaysChangeInput;
use App\Domain\Placement\ClockTime;
use App\Domain\Placement\WorkoutTime;
use App\Models\Mesocycle;
use App\Models\Profile;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Today\DayPlans;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakeRebuild;
use Tests\Support\SheetSeed;
use Tests\Support\TrainSeed;

// SCH-039: changing the training weekdays mid-mesocycle applies from the next plan week. Today is Tuesday 22 September
// 2026. The plan's weeks start on Mondays; Meso 2 (5 weeks from Monday 7 September, the deload last) trains Upper A on
// Monday and Lower A on Thursday at 5:30 PM, so this plan week (21 to 27 September) is its week 3 and the next plan
// week its week 4 (index 3). The rebuild of the days after today is FakeRebuild (SET-004), so these tests show that it
// runs, not what it writes. The core rule's reshaping is TrainingWeekdaysTest's.
uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/** @return array{User, Mesocycle} */
function weekdayWorld(): array
{
    Carbon::setTestNow('2026-09-22 08:00:00');
    $user = TrainSeed::user();
    TrainSeed::phase($user, 'muscleGain', '2026-08-31');

    return [$user, TrainSeed::mesocycle($user)];
}

function changeWeekdays(User $user, array $weekdays, ?string $fewerDays = null): array
{
    return app(ChangeTrainingWeekdays::class)($user, TrainingWeekdaysChangeInput::validate(['weekdays' => $weekdays, 'fewer_days' => $fewerDays]));
}

function weekdayRefusal(User $user, array $weekdays, ?string $fewerDays = null): ?FieldRefused
{
    try {
        changeWeekdays($user, $weekdays, $fewerDays);
    } catch (FieldRefused $e) {
        return $e;
    }

    return null;
}

/** @return list<array<string, mixed>> */
function trainingDaysOf(User $user): array
{
    return Profile::query()->where('user_id', $user->id)->sole()->training_days;
}

/** @return list<string> each session row as "week/day at Y-m-d H:i" */
function sessionRows(Mesocycle $mesocycle): array
{
    return WorkoutSession::query()->where('mesocycle_id', $mesocycle->id)->orderBy('week')->orderBy('day')->get()
        ->map(fn (WorkoutSession $row) => "{$row->week}/{$row->day} at {$row->planned_start_at->format('Y-m-d H:i')}")->all();
}

it('SCH-039 the same number of days re-maps: the new weekdays are saved, this week\'s sessions stay on their days, and the mesocycle\'s days take the new weekdays', function () {
    [$user, $mesocycle] = weekdayWorld();
    $rebuild = FakeRebuild::bind();
    $before = $mesocycle->fresh()->only(['weeks', 'earlier_weeks', 'later_weeks']);

    changeWeekdays($user, [5, 2]);

    expect(trainingDaysOf($user))->toEqual([
        ['weekday' => 2, 'start' => '17:30', 'minutes' => 60],
        ['weekday' => 5, 'start' => '17:30', 'minutes' => 60],
    ])
        ->and(sessionRows($mesocycle))->toBe(['3/1 at 2026-09-21 17:30', '3/2 at 2026-09-24 17:30'])
        ->and(array_column($mesocycle->fresh()->days, 'weekday'))->toBe([2, 5])
        ->and(array_column($mesocycle->fresh()->days, 'label'))->toBe(['Upper A', 'Lower A'])
        ->and($mesocycle->fresh()->only(['weeks', 'earlier_weeks', 'later_weeks']))->toEqual($before)
        ->and($rebuild->calls)->toHaveCount(1);
});

it('SCH-039 a weekday that stays keeps its start time and minutes', function () {
    [$user] = weekdayWorld();
    Profile::query()->where('user_id', $user->id)->update(['training_days' => json_encode([
        ['weekday' => 1, 'start' => '06:00', 'minutes' => 75],
        ['weekday' => 4, 'start' => '17:30', 'minutes' => 60],
    ])]);
    FakeRebuild::bind();

    changeWeekdays($user, [1, 6]);

    expect(trainingDaysOf($user))->toEqual([
        ['weekday' => 1, 'start' => '06:00', 'minutes' => 75],
        ['weekday' => 6, 'start' => '17:30', 'minutes' => 60],
    ]);
});

it('SCH-039 a session logged this week gets no second row', function () {
    [$user, $mesocycle] = weekdayWorld();
    TrainSeed::trained($mesocycle, 3, 1, '2026-09-21');
    FakeRebuild::bind();

    changeWeekdays($user, [2, 5]);

    expect(WorkoutSession::query()->where('mesocycle_id', $mesocycle->id)->where('week', 3)->where('day', 1)->count())->toBe(1)
        ->and(WorkoutSession::query()->where('mesocycle_id', $mesocycle->id)->where('week', 3)->where('day', 2)->sole()->planned_start_at->format('Y-m-d H:i'))->toBe('2026-09-24 17:30');
});

it('SCH-039 a session moved this week keeps its row and its time', function () {
    [$user, $mesocycle] = weekdayWorld();
    PlannedSession::saveRow($user, null, $mesocycle->id, 3, 2, DayPlans::day('2026-09-25'), new WorkoutTime(ClockTime::at(12), 60));
    FakeRebuild::bind();

    changeWeekdays($user, [2, 5]);

    expect(sessionRows($mesocycle))->toBe(['3/1 at 2026-09-21 17:30', '3/2 at 2026-09-25 12:00']);
});

it('SCH-039 fewer days without a choice is refused on fewer_days, saying what each choice does, and nothing is saved', function () {
    [$user, $mesocycle] = weekdayWorld();
    $rebuild = FakeRebuild::bind();
    $before = $mesocycle->fresh()->only(['days', 'weeks', 'earlier_weeks', 'later_weeks']);

    $refusal = weekdayRefusal($user, [3]);

    expect($refusal?->field)->toBe('fewer_days')
        ->and($refusal?->getMessage())->toContain('Restructure from next week', 'Keep all sessions', 'runs 6 weeks instead of 5')
        ->and(trainingDaysOf($user))->toEqual([
            ['weekday' => 1, 'start' => '17:30', 'minutes' => 60],
            ['weekday' => 4, 'start' => '17:30', 'minutes' => 60],
        ])
        ->and(WorkoutSession::query()->count())->toBe(0)
        ->and($mesocycle->fresh()->only(['days', 'weeks', 'earlier_weeks', 'later_weeks']))->toEqual($before)
        ->and($rebuild->calls)->toBe([]);
});

it('SCH-039 extend keeps all sessions: each later week finishes fewer of them, so the mesocycle runs longer, and the weeks run so far are stored', function () {
    [$user, $mesocycle] = weekdayWorld();
    FakeRebuild::bind();

    changeWeekdays($user, [3], 'extend');

    $stored = $mesocycle->fresh();
    expect($stored->weeks)->toBe(6)
        ->and($stored->later_weeks)->toBe([['01JB6Q400A2B3C4D5E6F7G8H9J'], ['01JB6Q410A2B3C4D5E6F7G8H9J']])
        ->and($stored->earlier_weeks)->toHaveCount(3)
        ->and(array_column($stored->earlier_weeks[2], 'label'))->toBe(['Upper A', 'Lower A'])
        ->and(array_column($stored->days, 'label'))->toBe(['Upper A', 'Lower A'])
        ->and(array_column($stored->days, 'weekday'))->toBe([null, null])
        ->and(trainingDaysOf($user))->toEqual([['weekday' => 3, 'start' => '17:30', 'minutes' => 60]])
        ->and(sessionRows($mesocycle))->toBe(['3/1 at 2026-09-21 17:30', '3/2 at 2026-09-24 17:30']);
});

it('SCH-039 restructure lays the muscles out over the new days from next week, keeping each muscle\'s exercise, the length and the weeks run so far', function () {
    [$user, $mesocycle] = weekdayWorld();
    FakeRebuild::bind();
    $bench = $mesocycle->days[0]['slots'][0]['exercise_id'];

    changeWeekdays($user, [3], 'restructure');

    $stored = $mesocycle->fresh();
    $slots = collect($stored->days)->flatMap(fn (array $day) => $day['slots']);
    expect($stored->weeks)->toBe(5)
        ->and($stored->later_weeks)->toBe([])
        ->and($stored->earlier_weeks)->toHaveCount(3)
        ->and($stored->days)->toHaveCount(1)
        ->and($stored->days_per_week)->toBe(1)
        ->and(array_column($stored->days, 'weekday'))->toBe([null])
        ->and($slots->firstWhere('muscle_group', 'chest')['exercise_id'])->toBe($bench)
        ->and($slots->sum('sets'))->toBeGreaterThan(0);
});

it('SCH-039 more days asks nothing and applies no choice: the days float and the mesocycle keeps its shape', function () {
    [$user, $mesocycle] = weekdayWorld();
    FakeRebuild::bind();
    $before = $mesocycle->fresh()->only(['weeks', 'earlier_weeks', 'later_weeks']);

    changeWeekdays($user, [1, 3, 4], 'restructure');

    expect(array_column(trainingDaysOf($user), 'weekday'))->toBe([1, 3, 4])
        ->and(array_column($mesocycle->fresh()->days, 'label'))->toBe(['Upper A', 'Lower A'])
        ->and(array_column($mesocycle->fresh()->days, 'weekday'))->toBe([null, null])
        ->and($mesocycle->fresh()->only(['weeks', 'earlier_weeks', 'later_weeks']))->toEqual($before);
});

it('SCH-039 MESO-042 extend is refused when the longer mesocycle would run into the next one', function () {
    [$user, $mesocycle] = weekdayWorld();
    TrainSeed::mesocycle($user, ['name' => 'Meso 3', 'start_date' => '2026-10-12', 'weeks' => 4]);
    FakeRebuild::bind();
    $before = $mesocycle->fresh()->only(['days', 'weeks', 'earlier_weeks', 'later_weeks']);

    $refusal = weekdayRefusal($user, [3], 'extend');

    expect($refusal?->field)->toBe('fewer_days')
        ->and($refusal?->getMessage())->toBe(ChangeTrainingWeekdays::CANNOT_EXTEND)
        ->and($mesocycle->fresh()->only(['days', 'weeks', 'earlier_weeks', 'later_weeks']))->toEqual($before);
});

it('SCH-039 with no mesocycle running next week, fewer days saves as usual and this week\'s sessions stay on their days', function () {
    [$user, $mesocycle] = weekdayWorld();
    $mesocycle->update(['weeks' => 3]);
    FakeRebuild::bind();

    expect(weekdayRefusal($user, [3]))->toBeNull()
        ->and(array_column(trainingDaysOf($user), 'weekday'))->toBe([3])
        ->and(sessionRows($mesocycle))->toBe(['3/1 at 2026-09-21 17:30', '3/2 at 2026-09-24 17:30']);
});

it('SCH-039 an ended mesocycle is not reshaped, so fewer days asks nothing', function () {
    [$user, $mesocycle] = weekdayWorld();
    $mesocycle->update(['ended_on' => '2026-09-21']);
    FakeRebuild::bind();
    $before = $mesocycle->fresh()->only(['days', 'weeks', 'earlier_weeks', 'later_weeks']);

    expect(weekdayRefusal($user, [3]))->toBeNull()
        ->and($mesocycle->fresh()->only(['days', 'weeks', 'earlier_weeks', 'later_weeks']))->toEqual($before);
});

it('SCH-039 refuses an empty set of training days, naming weekdays', function () {
    [$user] = weekdayWorld();
    FakeRebuild::bind();

    expect(weekdayRefusal($user, [])?->field)->toBe('weekdays');
});

it('SCH-039 SCH-056 re-targets today\'s unchecked meals with the new schedule', function () {
    [$user] = weekdayWorld();
    FakeRebuild::bind();
    $today = SheetSeed::day($user, '2026-09-22', ['day_type' => 'rest', 'kcal' => 2200.0, 'protein_g' => 150.0, 'fat_g' => 60.0, 'carbs_g' => 250.0]);

    $changed = changeWeekdays($user, [2, 5]);

    expect($changed['toast'])->toBe(ChangeTrainingWeekdays::RETARGETED)
        ->and($changed['today'])->toBe(['date' => '2026-09-22', 'week' => ['2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25', '2026-09-26', '2026-09-27']])
        ->and(collect($today->fresh()->meals)->sum('kcal'))->toEqualWithDelta($today->fresh()->kcal, 1.0);
});

it('SCH-039 SCH-052 today\'s session that already had a row keeps its time, and today\'s meals are placed around it', function () {
    [$user, $mesocycle] = weekdayWorld();
    Carbon::setTestNow('2026-09-24 08:00:00');
    $today = SheetSeed::day($user, '2026-09-24');
    PlannedSession::saveRow($user, null, $mesocycle->id, 3, 2, DayPlans::day('2026-09-24'), new WorkoutTime(ClockTime::at(12), 60));
    FakeRebuild::bind();

    changeWeekdays($user, [4, 6]);

    // SCH-042: a post-workout meal starts within an hour of the 1 PM end, not around the weekday's 5:30 PM.
    expect(array_filter(array_column($today->fresh()->meals, 'time'), fn (string $time) => $time >= '13:00' && $time <= '14:00'))->not->toBeEmpty();
});

it('SCH-039 the stored weekdays sent again change nothing', function () {
    [$user, $mesocycle] = weekdayWorld();
    $rebuild = FakeRebuild::bind();
    SheetSeed::day($user, '2026-09-22', ['day_type' => 'rest', 'kcal' => 2200.0]);

    $changed = changeWeekdays($user, [4, 1]);

    expect($changed['toast'])->toBeNull()
        ->and(WorkoutSession::query()->count())->toBe(0)
        ->and($rebuild->calls)->toBe([]);
});

it('SCH-039 SCH-055 today with a checked-in meal is not re-targeted', function () {
    [$user] = weekdayWorld();
    FakeRebuild::bind();
    $day = SheetSeed::day($user, '2026-09-22', ['day_type' => 'rest', 'kcal' => 2200.0]);
    SheetSeed::checkIn(SheetSeed::id(SheetSeed::BREAKFAST, '260922'));
    $meals = $day->fresh()->meals;

    $unchanged = changeWeekdays($user, [2, 5]);

    expect($day->fresh()->meals)->toEqual($meals)
        ->and($unchanged['toast'])->toBeNull();
});
