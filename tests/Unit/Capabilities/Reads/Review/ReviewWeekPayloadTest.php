<?php

use App\Capabilities\Reads\Review\ReviewWeekPayload;
use App\Domain\Defaults\TBL012TrainingDefaults\Column;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Progression\RecoveryStep;
use App\Domain\Progression\VolumeProgression;
use App\Models\DayTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\ReviewSeed;
use Tests\Support\ReviewShowWorld;

// Steps 1 to 4 of review_show (rp-clone ReviewSteps and WeeklyReviewFlowTests): weigh-ins, meals, training and progress.

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow(ReviewSeed::NOW));
afterEach(fn () => Carbon::setTestNow());

/** @return array<string, mixed> */
function rvWeighIns(?Closure $change = null): array
{
    return ReviewWeekPayload::weighIns(ReviewShowWorld::analysis($change))->toArray();
}

/** @return array<string, mixed> */
function rvMeals(?Closure $change = null): array
{
    return ReviewWeekPayload::meals(ReviewShowWorld::analysis($change))->toArray();
}

/** @return array<string, mixed> */
function rvTraining(?Closure $change = null): array
{
    return ReviewWeekPayload::training(ReviewShowWorld::analysis($change))->toArray();
}

/** @return array<string, mixed> */
function rvProgress(?Closure $change = null): array
{
    return ReviewWeekPayload::progress(ReviewShowWorld::analysis($change))->toArray();
}

// MARK: step 1

it('REV-003 the weigh-ins step lists each day of the week with its weight and flags a missing one with a late add', function () {
    $payload = rvWeighIns(fn (ReviewSeed $seed) => $seed->weight = fn (int $offset): ?float => $offset === -4 ? null : 89.0);

    $missing = array_values(array_filter($payload['rows'], fn (array $row) => $row['is_missing']));
    expect($payload['rows'])->toHaveCount(7)
        ->and($payload['rows'][0])->toBe([
            'date' => '2026-10-05', 'date_label' => 'Mon, 5 Oct', 'value_label' => '89.0 kg', 'is_missing' => false,
            'add_at' => '2026-10-05T07:00:00+00:00',
        ])
        ->and($missing)->toBe([[
            'date' => '2026-10-08', 'date_label' => 'Thu, 8 Oct', 'value_label' => '', 'is_missing' => true,
            'add_at' => '2026-10-08T07:00:00+00:00',
        ]])
        ->and($payload['empty_message'])->toBeNull()
        ->and($payload['missing'])->toBe(['text' => 'Missing · Add', 'tone' => 'warning'])
        ->and($payload['add_title'])->toBe('Add a weigh-in');
});

it('SCH-061 REV-003 an away week with nothing scheduled or logged says so and marks no weigh-in missing', function () {
    $payload = rvWeighIns(function (ReviewSeed $seed) {
        $seed->planWeekRows = [['start_date' => '2026-10-05', 'is_away' => true, 'away_training' => 'pause', 'away_calories' => 'maintenance']];
        $seed->weighInWeekdays = [];
        $seed->weight = fn (int $offset): ?float => $offset < -7 ? 90.0 : null;
    });

    expect($payload['rows'])->toBe([])
        ->and($payload['empty_message'])->toBe('No weigh-ins were scheduled or logged this week.');
});

// MARK: step 2

it('REV-003 checking in every meal is excellent work, with nothing to check in', function () {
    $payload = rvMeals();

    $marks = array_merge(...array_map(fn (array $day) => $day['meals'], $payload['days']));
    expect($payload['message'])->toBe(['text' => 'Excellent work', 'tone' => 'good'])
        ->and($payload['days'])->toHaveCount(7)
        ->and(array_unique(array_column($marks, 'is_checked')))->toBe([true]);
});

it('REV-003 REV-006 meals not checked in are marked to check in now, by their id, on their day', function () {
    $payload = rvMeals(fn (ReviewSeed $seed) => $seed->isChecked = fn (string $date, int $meal) => ! ($date === '2026-10-08' && $meal === 2)
        && ! ($date === '2026-10-10' && $meal === 4));

    $ids = fn (string $date, int $meal) => strtolower((string) DayTarget::query()->where('date', $date)->firstOrFail()->meals[$meal - 1]['id']);
    $open = [];
    foreach ($payload['days'] as $day) {
        foreach ($day['meals'] as $meal) {
            if (! $meal['is_checked']) {
                $open[] = ['day' => $day['date'], 'meal_id' => $meal['meal_id'], 'label' => $meal['label']];
            }
        }
    }
    expect($payload['message'])->toBe(['text' => '26 of 28 meals checked in', 'tone' => 'neutral'])
        ->and($open)->toBe([
            ['day' => '2026-10-08', 'meal_id' => $ids('2026-10-08', 2), 'label' => 'Check in Meal 2, Thu, 8 Oct'],
            ['day' => '2026-10-10', 'meal_id' => $ids('2026-10-10', 4), 'label' => 'Check in Meal 4, Sat, 10 Oct'],
        ]);
});

it('REV-006 MEAL-047 each meal\'s mark carries its check-in state, on track, under or over, and says it', function (float $share, string $state, string $words) {
    $payload = rvMeals(function (ReviewSeed $seed) use ($share) {
        $seed->eatenShare = $share;
        $seed->isChecked = fn (string $date, int $meal) => ! ($date === '2026-10-08' && $meal === 2);
    });

    $marks = collect($payload['days'])->flatMap(fn (array $day) => $day['meals']);
    expect($marks->firstWhere('label', 'Check in Meal 2, Thu, 8 Oct')['check_in'])->toBe('not_checked_in')
        ->and($marks->where('is_checked', true)->pluck('check_in')->unique()->all())->toBe([$state])
        ->and($marks->firstWhere('is_checked', true)['label'])->toEndWith(": {$words}");
})->with([
    'on track' => [1.0, 'on_track', 'checked in, on track'],
    'under' => [0.5, 'under', 'checked in, under target'],
    'over' => [1.5, 'over', 'checked in, over target'],
]);

it('REV-006 still sends the meals not checked in as unchecked, with check_in_title, for one release', function () {
    $payload = rvMeals(fn (ReviewSeed $seed) => $seed->isChecked = fn (string $date, int $meal) => ! ($date === '2026-10-08' && $meal === 2));

    $id = strtolower((string) DayTarget::query()->where('date', '2026-10-08')->firstOrFail()->meals[1]['id']);
    expect($payload['unchecked'])->toBe([['meal_id' => $id, 'name' => 'Meal 2', 'date_label' => 'Thu, 8 Oct']])
        ->and($payload['check_in_title'])->toBe('Check in now');
});

// MARK: step 3

it('REV-003 the training step counts sessions done, skipped and missed and the sets against the plan', function () {
    $payload = rvTraining(function (ReviewSeed $seed) {
        $seed->sessionsDone = 1;
        $seed->sessionsSkipped = 1;
    });

    expect($payload['lines'])->toBe([
        ['label' => 'Sessions done', 'value' => '1', 'tone' => 'neutral'],
        ['label' => 'Skipped', 'value' => '1', 'tone' => 'neutral'],
        ['label' => 'Missed', 'value' => '1', 'tone' => 'warning'],
        ['label' => 'Sets done', 'value' => '7 of 20 planned', 'tone' => 'neutral'],
    ]);
});

it('REV-003 a fully trained week misses nothing and has no training notes', function () {
    $payload = rvTraining();

    expect($payload['lines'][2])->toBe(['label' => 'Missed', 'value' => '0', 'tone' => 'neutral'])
        ->and($payload['lines'][3]['value'])->toBe('20 of 20 planned')
        ->and($payload['notes'])->toBe([])
        ->and($payload['next_week_label'])->toBe('Next week');
});

it('PROG-050 the training step flags a muscle that missed its reps two sessions running', function () {
    $payload = rvTraining(fn (ReviewSeed $seed) => $seed->chestUnderperforms = true);

    expect($payload['notes'])->toBe([['text' => 'Missed rep targets 2 sessions running: Chest', 'tone' => 'warning']]);
});

it('REV-003 the training step flags a muscle at its volume ceiling', function () {
    $ceiling = VolumeProgression::landmarks(MuscleGroup::Chest, ExperienceLevel::Intermediate, Column::for(PhaseType::FatLoss, false))->mrv;

    $payload = rvTraining(fn (ReviewSeed $seed) => $seed->chestSlotSets = $ceiling);

    expect($payload['notes'])->toBe([['text' => 'At the volume ceiling: Chest', 'tone' => 'info']]);
});

it('FB-002 the training step flags joint pain with the exercise and its level', function () {
    $payload = rvTraining(fn (ReviewSeed $seed) => $seed->exerciseFeedback = [
        ['session' => ReviewSeed::sessionKey(1), 'exercise' => 'Bench Press', 'pain' => 'moderate'],
        ['session' => ReviewSeed::sessionKey(2), 'exercise' => 'Back Squat', 'pain' => 'none'],
    ]);

    expect($payload['notes'])->toBe([['text' => 'Bench Press: Moderate pain', 'tone' => 'warning']]);
});

it('REV-003 the training step says what next week is', function () {
    // A 6-week mesocycle from the plan start: next week is its fifth, the last hard week.
    $weeks = fn (int $weeks) => rvTraining(fn (ReviewSeed $seed) => $seed->mesocycleWeeks = $weeks)['next_week'];

    expect($weeks(6))->toBe(['text' => 'Last hard week', 'tone' => 'neutral'])
        ->and($weeks(8))->toBe(['text' => 'Normal week', 'tone' => 'neutral'])
        ->and($weeks(5))->toBe(['text' => 'Deload week', 'tone' => 'info']);
});

it('REV-045 on target in Muscle gain with high training fatigue, the lifts are a training problem and the recovery step is shown', function () {
    $payload = rvTraining(function (ReviewSeed $seed) {
        ReviewShowWorld::muscleGain($seed);
        $seed->chestUnderperforms = true;
        $seed->backUnderperforms = true;
    });

    expect(array_slice($payload['notes'], -2))->toBe([
        ['text' => 'Your weight is on track. The lifts are a training problem, not a food one.', 'tone' => 'info'],
        ['text' => 'Recovery step: half sessions for the rest of the week, or an early deload.', 'tone' => 'info'],
    ]);
});

it('PROG-052 REV-045 high fatigue from feedback alone gives the sentence without a recovery step, which needs two underperforming muscles', function () {
    $payload = rvTraining(function (ReviewSeed $seed) {
        ReviewShowWorld::muscleGain($seed);
        $seed->muscleFeedback = ReviewSeed::highFatigue(ReviewSeed::sessionKey(1));
    });

    expect(array_column($payload['notes'], 'text'))->toBe(['Your weight is on track. The lifts are a training problem, not a food one.']);
});

it('PROG-052 REV-045 the recovery step names the steps PROG-052 recommends for Muscle gain, and never a diet break', function () {
    $payload = rvTraining(function (ReviewSeed $seed) {
        ReviewShowWorld::muscleGain($seed);
        $seed->chestUnderperforms = true;
        $seed->backUnderperforms = true;
    });
    $steps = array_map(fn ($step) => $step->value, RecoveryStep::recommended([MuscleGroup::Chest, MuscleGroup::Back], PhaseType::MuscleGain));
    $last = array_column($payload['notes'], 'text')[count($payload['notes']) - 1];

    expect($steps)->toBe(['halfSessionsForRestOfWeek', 'earlyDeload'])
        ->and($last)->toBe('Recovery step: half sessions for the rest of the week, or an early deload.')
        ->and($last)->not->toContain('diet break');
});

it('REV-045 shows nothing of it in Fat loss, off target, or without high training fatigue', function () {
    $fatLoss = rvTraining(function (ReviewSeed $seed) {
        $seed->chestUnderperforms = true;
        $seed->backUnderperforms = true;
    });
    $offTarget = rvTraining(function (ReviewSeed $seed) {
        ReviewShowWorld::muscleGain($seed);
        $seed->weight = fn (int $offset): float => 90.0;
        $seed->chestUnderperforms = true;
        $seed->backUnderperforms = true;
    });
    $rested = rvTraining(fn (ReviewSeed $seed) => ReviewShowWorld::muscleGain($seed));

    $texts = fn (array $payload) => array_column($payload['notes'], 'text');
    expect($texts($fatLoss))->not->toContain('Your weight is on track. The lifts are a training problem, not a food one.')
        ->and($texts($offTarget))->not->toContain('Your weight is on track. The lifts are a training problem, not a food one.')
        ->and($texts($rested))->toBe([]);
});

it('REV-003 a user who weighs in pounds reads the weigh-ins and the progress lines in pounds', function () {
    $pounds = fn (ReviewSeed $seed) => $seed->weightUnit = 'lb';
    $weighIns = rvWeighIns(function (ReviewSeed $seed) {
        $seed->weightUnit = 'lb';
        $seed->weight = fn (int $offset): ?float => 89.0;
    });
    $progress = rvProgress($pounds);
    $kg = rvProgress();

    expect($weighIns['rows'][0]['value_label'])->toBe('196.2 lb')
        ->and($progress['lines'][0]['value'])->toMatch('/^\d{3}\.\d lb$/')
        ->and($progress['lines'][1]['value'])->toMatch('/^\d{3}\.\d lb to \d{3}\.\d lb$/')
        // Same trend, converted: kg x 2.20462.
        ->and((float) $progress['lines'][0]['value'])->toEqualWithDelta((float) $kg['lines'][0]['value'] * 2.20462, 0.2);
});

// MARK: step 4

it('REV-003 the progress step places the trend in the goal range and the rate against the target', function () {
    $payload = rvProgress();

    expect(array_column($payload['lines'], 'label'))->toBe(['Trend weight', 'Goal range', 'This week\'s rate', 'Target rate'])
        ->and($payload['lines'][0]['value'])->toMatch('/^\d+\.\d kg$/')
        ->and($payload['lines'][1]['value'])->toMatch('/^\d+\.\d kg to \d+\.\d kg$/')
        ->and($payload['lines'][2]['value'])->toMatch('/^-0\.\d\d% a week$/')
        ->and($payload['lines'][3]['value'])->toBe('-0.50% a week')
        ->and($payload['pills'])->toBe([
            ['text' => 'Inside the goal range', 'tone' => 'good'],
            ['text' => 'On target', 'tone' => 'good'],
        ])
        ->and($payload['empty_message'])->toBeNull();
});

it('REV-003 the progress step warns when the trend is outside the goal range and the rate is off target', function () {
    $payload = rvProgress(fn (ReviewSeed $seed) => $seed->weight = fn (int $offset): float => 90.0);

    expect($payload['pills'][0]['tone'])->toBe('warning')
        ->and($payload['pills'][1])->toBe(['text' => 'Off target', 'tone' => 'warning']);
});
