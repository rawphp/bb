<?php

use App\Actions\Review\AnswerReview;
use App\Actions\Review\CompleteReview;
use App\Actions\Review\OpenReview;
use App\Capabilities\Reads\Review\ReviewShowPayload;
use App\Capabilities\Review\Data\ReviewAnswerInput;
use App\Domain\Review\DeloadMove;
use App\Domain\WeekBuilder\MealNames;
use App\Models\DayTarget;
use App\Models\Mesocycle;
use App\Models\PlanWeek;
use App\Models\Profile;
use App\Models\ReviewAnswer;
use App\Models\User;
use App\Models\WeighIn;
use App\Support\Review\ReviewAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\ReviewSeed;

// review_complete (rp-clone WeeklyReviewModel+Save and WeeklyReviewSaveTests): next week's plan week and days, the
// moved deload, the step plan, and last the review stamp (REV-001, REV-002, REV-005, REV-011, REV-027, REV-031,
// REV-050, SCH-061, MESO-044). The HTTP seam is tests/Feature/Capabilities/Progress/ReviewCompleteTest.php.

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow(ReviewSeed::NOW));
afterEach(fn () => Carbon::setTestNow());

const RQC_NEXT_WEEK = ['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16', '2026-10-17', '2026-10-18'];

function rqcUser(?Closure $change = null): User
{
    $seed = new ReviewSeed;
    if ($change !== null) {
        $change($seed);
    }

    return $seed->seed();
}

/** @param  array<string, mixed>  $input */
function rqcAnswer(User $user, array $input): void
{
    app(AnswerReview::class)($user, ReviewAnswerInput::from(['week_start' => '2026-10-05', 'option' => 'recommendation', ...$input]));
}

function rqcSave(User $user): void
{
    app(CompleteReview::class)($user, '2026-10-05');
}

/** @return list<DayTarget> next week's stored days, in date order */
function rqcDays(User $user): array
{
    return DayTarget::query()->where('user_id', $user->id)->whereBetween('date', ['2026-10-12', '2026-10-18'])->orderBy('date')->get()->all();
}

/** @return list<float> */
function rqcKcal(User $user): array
{
    return array_map(fn (DayTarget $day) => $day->kcal, rqcDays($user));
}

function rqcAverage(User $user): float
{
    return array_sum(rqcKcal($user)) / 7;
}

function rqcWeek(User $user, string $start): ?PlanWeek
{
    return PlanWeek::query()->where('user_id', $user->id)->whereDate('start_date', $start)->first();
}

function rqcMesocycleWeeks(User $user): int
{
    return (int) Mesocycle::query()->where('user_id', $user->id)->sole()->weeks;
}

/** @return list<array{min: int, max: int}> */
function rqcStepPlan(User $user): array
{
    return array_map(fn (array $day) => ['min' => $day['min'], 'max' => $day['max']], Profile::query()->where('user_id', $user->id)->sole()->step_plan);
}

/** @return array<string, list<array<string, mixed>>> what Save may change, as stored */
function rqcStored(User $user): array
{
    return [
        'plan_weeks' => DB::table('plan_weeks')->where('user_id', $user->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'day_targets' => DB::table('day_targets')->where('user_id', $user->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'mesocycles' => DB::table('mesocycles')->where('user_id', $user->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'profiles' => DB::table('profiles')->where('user_id', $user->id)->get()->map(fn ($row) => (array) $row)->all(),
    ];
}

/** Every write fails at the review stamp (REV-001): the reviewed week's row saved with reviewed_at. */
function rqcFailAtTheStamp(): void
{
    Event::listen('eloquent.saving: '.PlanWeek::class, function (PlanWeek $week) {
        if ($week->reviewed_at !== null) {
            throw new RuntimeException('The stamp failed.');
        }
    });
}

/** The weigh-ins change after an answer, and the stall is gone: the plan's own rate, as ReviewSeed's default. */
function rqcClearTheStall(User $user): void
{
    foreach (WeighIn::query()->where('user_id', $user->id)->get() as $weighIn) {
        $offset = (int) Carbon::parse(ReviewSeed::NEXT_WEEK_START)->diffInDays(Carbon::parse($weighIn->weighed_at)->startOfDay(), false);
        $weighIn->update(['weight_kg' => round(90 - 0.0643 * ($offset + 28), 2)]);
    }
}

// MARK: REV-001

it('REV-001 saving creates next week and its days, and stamps the week reviewed', function () {
    $user = rqcUser();

    rqcSave($user);

    $next = rqcWeek($user, '2026-10-12');
    $reviewed = rqcWeek($user, '2026-10-05');
    $days = rqcDays($user);
    expect($next)->not->toBeNull()
        ->and($next->is_deload)->toBeFalse()
        ->and($next->is_diet_break)->toBeFalse()
        ->and($next->reviewed_at)->toBeNull('next week is not reviewed yet')
        ->and($next->id)->toBe(strtolower($next->id))
        ->and(array_map(fn (DayTarget $day) => $day->date->format('Y-m-d'), $days))->toBe(RQC_NEXT_WEEK)
        ->and(rqcAverage($user))->toEqualWithDelta(2400, 1e-6)
        ->and($reviewed?->reviewed_at?->toIso8601ZuluString())->toBe('2026-10-12T09:00:00Z')
        ->and(rqcMesocycleWeeks($user))->toBe(6)
        ->and(rqcStepPlan($user))->toBe(array_fill(0, 7, ['min' => 6000, 'max' => 8000]))
        ->and(ReviewAnalysis::for($user, ReviewSeed::TODAY))->toBeNull('the review is done');
    foreach ($days as $day) {
        expect($day->meals)->toHaveCount(4)
            ->and(array_sum(array_column($day->meals, 'kcal')))->toEqualWithDelta($day->kcal, 1e-3)
            ->and($day->mealIndex()->count())->toBe(4);
    }
});

it('REV-001 the reviewed week\'s existing row is stamped with its flags kept', function () {
    $user = rqcUser(fn (ReviewSeed $seed) => $seed->planWeekRows = [
        ['id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'start_date' => '2026-10-05', 'is_diet_break' => true],
    ]);

    rqcSave($user);

    $reviewed = rqcWeek($user, '2026-10-05');
    expect($reviewed->id)->toBe('aaaaaaaa-0000-4000-8000-000000000001')
        ->and($reviewed->is_diet_break)->toBeTrue()
        ->and($reviewed->reviewed_at?->toIso8601ZuluString())->toBe('2026-10-12T09:00:00Z')
        ->and(PlanWeek::query()->where('user_id', $user->id)->whereDate('start_date', '2026-10-05')->count())->toBe(1);
});

it('REV-001 a save that fails leaves the review not done and nothing written, and saving again writes each row once', function () {
    $user = rqcUser();
    $before = rqcStored($user);
    rqcFailAtTheStamp();

    try {
        expect(fn () => rqcSave($user))->toThrow(RuntimeException::class, 'The stamp failed.')
            ->and(rqcStored($user))->toBe($before)
            ->and(ReviewAnalysis::for($user, ReviewSeed::TODAY))->not->toBeNull();
    } finally {
        Event::forget('eloquent.saving: '.PlanWeek::class);
    }

    rqcSave($user);
    expect(rqcDays($user))->toHaveCount(7)
        ->and(PlanWeek::query()->where('user_id', $user->id)->count())->toBe(2);
});

it('REV-001 a Drop fatigue save that fails at the stamp leaves next week and the mesocycle as they were, so a different answer saves cleanly', function () {
    $user = rqcUser();
    rqcAnswer($user, ['option' => 'drop_fatigue']);
    rqcFailAtTheStamp();
    try {
        expect(fn () => rqcSave($user))->toThrow(RuntimeException::class);
    } finally {
        Event::forget('eloquent.saving: '.PlanWeek::class);
    }
    expect(rqcWeek($user, '2026-10-12'))->toBeNull()
        ->and(rqcMesocycleWeeks($user))->toBe(6);

    rqcAnswer($user, ['option' => 'repeat_this_week']);
    rqcSave($user);

    $next = rqcWeek($user, '2026-10-12');
    expect($next->is_diet_break)->toBeFalse('the failed Drop fatigue is not read as a break marked ahead')
        ->and($next->is_deload)->toBeFalse()
        ->and(rqcMesocycleWeeks($user))->toBe(6)
        ->and(rqcAverage($user))->toEqualWithDelta(2400, 1e-6);
});

it('REV-001 refuses a week that is not the review open now, or one already reviewed, and writes nothing', function () {
    $user = rqcUser();
    $before = rqcStored($user);

    expect(fn () => app(CompleteReview::class)($user, '2026-09-28'))->toThrow(DomainException::class, 'There is no weekly review open for that week.')
        ->and(rqcStored($user))->toBe($before);

    rqcSave($user);
    $saved = rqcStored($user);
    expect(fn () => rqcSave($user))->toThrow(DomainException::class, 'There is no weekly review open for that week.')
        ->and(rqcStored($user))->toBe($saved);
});

it('NUT-022 refuses to save answers that do not build, and writes nothing', function () {
    $user = rqcUser();
    rqcAnswer($user, ['option' => 'choose_own', 'own_kcal' => 1000]);
    $before = rqcStored($user);

    expect(fn () => rqcSave($user))->toThrow(DomainException::class, 'That is too few calories to fit your protein, fat and carbs. Choose a higher average.')
        ->and(rqcStored($user))->toBe($before);
});

it('REV-010 refuses to save a Choose my own draft with no amount, and writes nothing', function () {
    $user = rqcUser();
    rqcAnswer($user, ['option' => 'choose_own']);
    $before = rqcStored($user);

    expect(fn () => rqcSave($user))->toThrow(DomainException::class, CompleteReview::NEEDS_AMOUNT)
        ->and(rqcStored($user))->toBe($before)
        ->and(ReviewAnalysis::for($user, ReviewSeed::TODAY))->not->toBeNull();
});

it('REV-010 a draft whose pre-filled amount the screen echoed with a move still needs an amount to save', function () {
    $user = rqcUser();
    rqcAnswer($user, ['option' => 'choose_own']);
    $shown = ReviewShowPayload::build(ReviewAnalysis::for($user, ReviewSeed::TODAY), ReviewAnswer::answersFor($user, '2026-10-05'))->toArray()['calories']['own']['value'];
    rqcAnswer($user, ['option' => 'choose_own', 'own_kcal' => $shown, 'moves' => [['from_index' => 0, 'to_index' => 5, 'kcal' => 100]]]);
    $before = rqcStored($user);

    expect(fn () => rqcSave($user))->toThrow(DomainException::class, CompleteReview::NEEDS_AMOUNT)
        ->and(rqcStored($user))->toBe($before);
});

it('REV-027 an answered option the review no longer offers is refused at save, not swapped for another, and nothing is written', function () {
    $user = rqcUser(fn (ReviewSeed $seed) => $seed->weight = fn (int $offset): float => 88.0);
    rqcAnswer($user, ['option' => 'cut_calories']);
    rqcClearTheStall($user);
    $before = rqcStored($user);

    expect(fn () => rqcSave($user))->toThrow(DomainException::class, CompleteReview::OUT_OF_DATE)
        ->and(rqcStored($user))->toBe($before);
});

it('REV-027 review_show says an answered option is out of date before Save does', function () {
    $user = rqcUser(fn (ReviewSeed $seed) => $seed->weight = fn (int $offset): float => 88.0);
    rqcAnswer($user, ['option' => 'cut_calories']);
    rqcClearTheStall($user);

    $analysis = ReviewAnalysis::for($user, ReviewSeed::TODAY);
    $shown = ReviewShowPayload::build($analysis, OpenReview::answers($user, $analysis))->toArray();

    expect($shown['preview']['error'])->toBe(CompleteReview::OUT_OF_DATE);
});

it('REV-005 SCH-061 an answer stored while the review was full does not block saving once it is training-only or away', function (string $option) {
    $user = rqcUser();
    rqcAnswer($user, ['option' => $option]);
    Profile::query()->where('user_id', $user->id)->update(['training_only' => true]);

    rqcSave($user);

    expect(rqcWeek($user, '2026-10-05')?->reviewed_at)->not->toBeNull()
        ->and(rqcDays($user))->toBe([]);
})->with(['repeat_this_week', 'choose_own', 'drop_fatigue']);

it('REV-010 a typed amount stays the amount when the screen sends it back with a move', function () {
    $user = rqcUser();
    rqcAnswer($user, ['option' => 'choose_own', 'own_kcal' => 2100]);
    rqcAnswer($user, ['option' => 'choose_own', 'own_kcal' => 2100, 'moves' => [['from_index' => 0, 'to_index' => 5, 'kcal' => 100]]]);

    rqcSave($user);

    expect(rqcAverage($user))->toEqualWithDelta(2100, 1e-6);
});

it('REV-010 Choose my own in two steps: the option, then its amount, then Save applies the amount', function () {
    $user = rqcUser();
    rqcAnswer($user, ['option' => 'choose_own']);
    rqcAnswer($user, ['option' => null, 'own_kcal' => 2300]);

    rqcSave($user);

    expect(rqcAverage($user))->toEqualWithDelta(2300, 1e-6);
});

// MARK: REV-004, REV-031

it('REV-004 moving calories between days changes what is saved, and the weekly total holds', function () {
    $plain = rqcUser();
    rqcSave($plain);
    $before = rqcKcal($plain);

    $moved = rqcUser();
    rqcAnswer($moved, ['moves' => [['from_index' => 0, 'to_index' => 5, 'kcal' => 100]]]);
    rqcSave($moved);
    $after = rqcKcal($moved);

    expect($after[0])->toEqualWithDelta($before[0] - 100, 1e-6)
        ->and($after[5])->toEqualWithDelta($before[5] + 100, 1e-6)
        ->and(array_sum($after))->toEqualWithDelta(array_sum($before), 1e-6);
});

it('REV-004 a refused move saves the moves that built before it', function () {
    $plain = rqcUser();
    rqcSave($plain);
    $before = rqcKcal($plain);

    $user = rqcUser();
    rqcAnswer($user, ['moves' => [['from_index' => 0, 'to_index' => 5, 'kcal' => 100]]]);
    rqcAnswer($user, ['moves' => [['from_index' => 0, 'to_index' => 5, 'kcal' => 100], ['from_index' => 0, 'to_index' => 5, 'kcal' => 900]]]);
    rqcSave($user);

    expect(rqcKcal($user)[0])->toEqualWithDelta($before[0] - 100, 1e-6)
        ->and(rqcKcal($user)[5])->toEqualWithDelta($before[5] + 100, 1e-6);
});

it('REV-031 nothing is applied until the user saves', function () {
    $user = rqcUser();
    $before = rqcStored($user);

    rqcAnswer($user, ['option' => 'drop_fatigue']);
    rqcAnswer($user, ['option' => 'choose_own', 'own_kcal' => 2100, 'update_step_plan' => true]);
    rqcAnswer($user, ['option' => 'choose_own', 'own_kcal' => 2100, 'moves' => [['from_index' => 0, 'to_index' => 5, 'kcal' => 100]]]);

    expect(rqcStored($user))->toBe($before)
        ->and(ReviewAnalysis::for($user, ReviewSeed::TODAY))->not->toBeNull();
});

// MARK: REV-002

it('REV-002 a late save gives days already past the repeated targets and today onward the chosen option', function () {
    $repeat = rqcUser();
    rqcAnswer($repeat, ['option' => 'repeat_this_week']);
    rqcSave($repeat);
    $chosen = rqcUser();
    rqcAnswer($chosen, ['option' => 'choose_own', 'own_kcal' => 2100]);
    rqcSave($chosen);

    Carbon::setTestNow('2026-10-14 10:00:00');
    $late = rqcUser();
    rqcAnswer($late, ['option' => 'choose_own', 'own_kcal' => 2100]);
    rqcSave($late);

    $repeated = rqcKcal($repeat);
    $result = rqcKcal($late);
    expect(array_slice($result, 0, 2))->toBe(array_slice($repeated, 0, 2), 'Monday and Tuesday were lived under the repeat')
        ->and(array_slice($result, 2))->toBe(array_slice(rqcKcal($chosen), 2), 'Wednesday onward gets the chosen option')
        ->and($repeated[0])->not->toBe(rqcKcal($chosen)[0]);
});

it('REV-002 REV-011 a late Drop fatigue that moves the deload still gives days already past the repeated targets', function () {
    Carbon::setTestNow('2026-10-14 10:00:00');
    $repeat = rqcUser();
    rqcAnswer($repeat, ['option' => 'repeat_this_week']);
    rqcSave($repeat);
    $dropped = rqcUser();
    rqcAnswer($dropped, ['option' => 'drop_fatigue']);
    rqcSave($dropped);

    $fields = fn (User $user) => array_map(fn (DayTarget $day) => [$day->kcal, $day->day_type, $day->carbs_g], array_slice(rqcDays($user), 0, 2));
    expect(rqcMesocycleWeeks($dropped))->toBe(5)
        ->and($fields($dropped))->toBe($fields($repeat), 'Monday and Tuesday were lived under the repeat');
});

it('REV-002 NUT-030 a day target already stored is replaced by its id and keeps its meal ids', function () {
    $user = rqcUser(fn (ReviewSeed $seed) => $seed->nextWeekHasTargets = true);
    $stored = [];
    foreach (rqcDays($user) as $day) {
        $stored[$day->date->format('Y-m-d')] = [$day->id, array_column($day->meals, 'id')];
    }

    rqcSave($user);

    $days = rqcDays($user);
    expect($days)->toHaveCount(7)
        ->and(DayTarget::withTrashed()->where('user_id', $user->id)->whereBetween('date', ['2026-10-12', '2026-10-18'])->count())->toBe(7);
    foreach ($days as $day) {
        expect([$day->id, array_column($day->meals, 'id')])->toBe($stored[$day->date->format('Y-m-d')], 'one day target per date, its meals keeping their ids');
    }
    expect(rqcAverage($user))->toEqualWithDelta(2400, 1e-6);
});

it('NUT-030 a stored intra-workout shake keeps its id off the regular meals: they match by their order among regular meals', function () {
    $user = rqcUser(fn (ReviewSeed $seed) => $seed->nextWeekHasTargets = true);
    $monday = DayTarget::query()->where('user_id', $user->id)->whereDate('date', '2026-10-12')->sole();
    $regular = array_column($monday->meals, 'id');
    $monday->meals = [
        ['id' => (string) Str::uuid7(), 'name' => MealNames::INTRA_WORKOUT_SHAKE, 'time' => '18:00', 'kcal' => 100, 'protein_g' => 10, 'fat_g' => 0, 'carbs_g' => 15],
        ...$monday->meals,
    ];
    $monday->save();

    rqcSave($user);

    expect(array_column(rqcDays($user)[0]->meals, 'id'))->toBe($regular);
});

// MARK: REV-005

it('REV-005 training-only saves the plan week with no day targets', function () {
    $user = rqcUser(fn (ReviewSeed $seed) => $seed->trainingOnly = true);

    rqcSave($user);

    expect(rqcDays($user))->toBe([])
        ->and(rqcWeek($user, '2026-10-12'))->not->toBeNull()
        ->and(rqcWeek($user, '2026-10-05')?->reviewed_at?->toIso8601ZuluString())->toBe('2026-10-12T09:00:00Z');
});

// MARK: SCH-061

it('SCH-061 saving an away week stamps it and keeps next week\'s away fields without adding a second row', function () {
    $user = rqcUser(function (ReviewSeed $seed) {
        $seed->planWeekRows = [
            ['id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'start_date' => '2026-10-05', 'is_away' => true, 'away_training' => 'pause', 'away_calories' => 'maintenance'],
            ['id' => 'aaaaaaaa-0000-4000-8000-000000000002', 'start_date' => '2026-10-12', 'is_away' => true, 'away_training' => 'deload', 'away_calories' => 'rest_days'],
        ];
        $seed->weight = fn (int $offset): ?float => $offset < -7 ? 90.0 : null;
    });

    rqcSave($user);

    $next = rqcWeek($user, '2026-10-12');
    $reviewed = rqcWeek($user, '2026-10-05');
    expect(PlanWeek::query()->where('user_id', $user->id)->count())->toBe(2)
        ->and($next->only(['id', 'is_away', 'away_training', 'away_calories']))->toBe([
            'id' => 'aaaaaaaa-0000-4000-8000-000000000002', 'is_away' => true, 'away_training' => 'deload', 'away_calories' => 'rest_days',
        ])
        ->and($reviewed->only(['id', 'away_training', 'away_calories']))->toBe([
            'id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'away_training' => 'pause', 'away_calories' => 'maintenance',
        ])
        ->and($reviewed->reviewed_at?->toIso8601ZuluString())->toBe('2026-10-12T09:00:00Z')
        ->and(rqcDays($user))->toHaveCount(7);
});

it('SCH-061 an away review returns to the last ordinary week\'s calories', function () {
    $user = rqcUser(function (ReviewSeed $seed) {
        $seed->planWeekRows = [['start_date' => '2026-10-05', 'is_away' => true, 'away_training' => 'pause', 'away_calories' => 'rest_days']];
        // The away week ate less; the weeks before it averaged 2,400.
        $seed->reviewedWeekShiftKcal = -300;
    });

    rqcSave($user);

    expect(rqcAverage($user))->toEqualWithDelta(2400, 1e-6);
});

it('SCH-061 REV-005 completing an away or training-only review answered under the only option it takes changes no calories', function () {
    // 10,000 steps a day, so a step offer exists for the answer to accept.
    $walked = fn (ReviewSeed $seed) => $seed->steps = array_combine(
        array_map(fn (int $offset) => ReviewSeed::day($offset), range(-7, -1)),
        array_fill(0, 7, 10000),
    );
    $awayWeek = function (ReviewSeed $seed) use ($walked) {
        $walked($seed);
        $seed->planWeekRows = [
            ['start_date' => '2026-10-05', 'is_away' => true, 'away_training' => 'pause', 'away_calories' => 'maintenance'],
        ];
    };
    $unanswered = rqcUser($awayWeek);
    rqcSave($unanswered);
    $answered = rqcUser($awayWeek);
    rqcAnswer($answered, ['option' => 'recommendation', 'update_step_plan' => true]);
    expect(ReviewAnalysis::for($answered, ReviewSeed::TODAY)?->stepOffer)->not->toBeNull('there is an offer to accept');
    rqcSave($answered);
    $trainingOnly = rqcUser(function (ReviewSeed $seed) use ($walked) {
        $walked($seed);
        $seed->trainingOnly = true;
    });
    rqcAnswer($trainingOnly, ['option' => 'recommendation', 'update_step_plan' => true]);
    expect(ReviewAnalysis::for($trainingOnly, ReviewSeed::TODAY)?->stepOffer)->not->toBeNull('there is an offer to accept');
    rqcSave($trainingOnly);

    expect(rqcKcal($answered))->toBe(rqcKcal($unanswered))
        ->and(rqcAverage($answered))->toEqualWithDelta(2400, 1e-6, 'the last ordinary week\'s calories')
        ->and(rqcStepPlan($answered))->toBe(array_fill(0, 7, ['min' => 6000, 'max' => 8000]))
        ->and(rqcDays($trainingOnly))->toBe([])
        ->and(rqcStepPlan($trainingOnly))->toBe(array_fill(0, 7, ['min' => 6000, 'max' => 8000]));
});

// MARK: MESO-044

it('MESO-044 a diet break marked ahead is updated and keeps its flag', function () {
    $user = rqcUser(fn (ReviewSeed $seed) => $seed->planWeekRows = [
        ['id' => 'aaaaaaaa-0000-4000-8000-000000000002', 'start_date' => '2026-10-12', 'is_diet_break' => true],
    ]);

    rqcSave($user);

    $next = rqcWeek($user, '2026-10-12');
    expect($next->id)->toBe('aaaaaaaa-0000-4000-8000-000000000002')
        ->and($next->is_diet_break)->toBeTrue()
        ->and(rqcDays($user))->toHaveCount(7)
        ->and(rqcAverage($user))->toBeGreaterThan(2400, 'the break eats at maintenance, above the cut');
});

// MARK: REV-011

it('REV-011 Drop fatigue with the deload one or two weeks away moves it to next week without asking', function () {
    // A 6-week mesocycle from the plan start; 4 weeks are done, so its deload (week 6) is 2 away.
    $user = rqcUser();
    rqcAnswer($user, ['option' => 'drop_fatigue', 'deload_too' => false]);

    rqcSave($user);

    $next = rqcWeek($user, '2026-10-12');
    expect(rqcMesocycleWeeks($user))->toBe(5, 'the deload lands on next week')
        ->and($next->is_deload)->toBeTrue()
        ->and($next->is_diet_break)->toBeTrue()
        ->and(rqcAverage($user))->toBeGreaterThan(2400, 'a diet break eats at maintenance');
});

it('REV-011 otherwise Yes, the default once three training weeks are done, moves the deload; No leaves training as planned', function () {
    $eight = fn (ReviewSeed $seed) => $seed->mesocycleWeeks = 8;
    $yes = rqcUser($eight);
    rqcAnswer($yes, ['option' => 'drop_fatigue']);
    rqcSave($yes);
    $no = rqcUser($eight);
    rqcAnswer($no, ['option' => 'drop_fatigue', 'deload_too' => false]);
    rqcSave($no);

    expect(rqcMesocycleWeeks($yes))->toBe(5)
        ->and(rqcWeek($yes, '2026-10-12')->is_deload)->toBeTrue()
        ->and(rqcWeek($yes, '2026-10-12')->is_diet_break)->toBeTrue()
        ->and(rqcMesocycleWeeks($no))->toBe(8)
        ->and(rqcWeek($no, '2026-10-12')->is_deload)->toBeFalse()
        ->and(rqcWeek($no, '2026-10-12')->is_diet_break)->toBeTrue();
});

it('REV-011 the default is No before three training weeks are done', function () {
    $user = rqcUser(function (ReviewSeed $seed) {
        $seed->weeksFinished = 2;
        $seed->mesocycleWeeks = 8;
    });
    rqcAnswer($user, ['option' => 'drop_fatigue']);

    rqcSave($user);

    expect(rqcMesocycleWeeks($user))->toBe(8)
        ->and(rqcWeek($user, '2026-10-12')->is_deload)->toBeFalse()
        ->and(rqcWeek($user, '2026-10-12')->is_diet_break)->toBeTrue();
});

it('REV-011 TBL-023 the moved deload stores a length whose TBL-023 RIR runs 3, 2, 1, 0', function () {
    $user = rqcUser();
    rqcAnswer($user, ['option' => 'drop_fatigue']);

    rqcSave($user);

    expect((new DeloadMove(rqcMesocycleWeeks($user)))->rirByWeek())->toBe([3, 2, 1, 0], 'TBL-023 for a 5-week mesocycle: 4 training weeks');
});

it('REV-011 an answer other than Drop fatigue never moves the deload, whatever deload_too says', function () {
    $user = rqcUser(fn (ReviewSeed $seed) => $seed->mesocycleWeeks = 8);
    rqcAnswer($user, ['option' => 'repeat_this_week', 'deload_too' => true]);

    rqcSave($user);

    expect(rqcMesocycleWeeks($user))->toBe(8)
        ->and(rqcWeek($user, '2026-10-12')->is_deload)->toBeFalse()
        ->and(rqcWeek($user, '2026-10-12')->is_diet_break)->toBeFalse();
});

// MARK: REV-027, REV-050

it('REV-027 adding steps raises the step plan on save', function () {
    $user = rqcUser(fn (ReviewSeed $seed) => $seed->weight = fn (int $offset): float => 88.0);
    rqcAnswer($user, ['option' => 'add_steps']);

    rqcSave($user);

    expect(rqcStepPlan($user))->toBe(array_fill(0, 7, ['min' => 8000, 'max' => 10000]));
});

it('REV-027 cutting calories on a stall saves the cut and leaves the step plan', function () {
    $user = rqcUser(fn (ReviewSeed $seed) => $seed->weight = fn (int $offset): float => 88.0);
    rqcAnswer($user, ['option' => 'cut_calories']);

    rqcSave($user);

    expect(rqcAverage($user))->toEqualWithDelta(2150, 1e-6)
        ->and(rqcStepPlan($user))->toBe(array_fill(0, 7, ['min' => 6000, 'max' => 8000]));
});

it('REV-027 REV-050 adding a stall\'s steps wins over an accepted step offer', function () {
    $user = rqcUser(function (ReviewSeed $seed) {
        $seed->weight = fn (int $offset): float => 88.0;
        $seed->steps = array_combine(array_map(fn (int $offset) => ReviewSeed::day($offset), range(-7, -1)), array_fill(0, 7, 10000));
    });
    rqcAnswer($user, ['option' => 'add_steps', 'update_step_plan' => true]);

    rqcSave($user);

    expect(rqcStepPlan($user))->toBe(array_fill(0, 7, ['min' => 8000, 'max' => 10000]));
});

it('REV-050 accepting the step offer updates the step plan on save, and declining leaves it', function () {
    $walked = fn (ReviewSeed $seed) => $seed->steps = array_combine(
        array_map(fn (int $offset) => ReviewSeed::day($offset), range(-7, -1)),
        array_fill(0, 7, 10000),
    );
    $accepted = rqcUser($walked);
    rqcAnswer($accepted, ['update_step_plan' => true]);
    rqcSave($accepted);
    $declined = rqcUser($walked);
    rqcSave($declined);

    expect(rqcStepPlan($accepted))->toBe(array_fill(0, 7, ['min' => 9000, 'max' => 11000]))
        ->and(rqcStepPlan($declined))->toBe(array_fill(0, 7, ['min' => 6000, 'max' => 8000]));
});

it('REV-050 an accepted step offer with no offer to accept changes nothing', function () {
    $user = rqcUser();
    rqcAnswer($user, ['update_step_plan' => true]);

    rqcSave($user);

    expect(rqcStepPlan($user))->toBe(array_fill(0, 7, ['min' => 6000, 'max' => 8000]));
});
