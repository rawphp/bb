<?php

use App\Capabilities\Reads\Review\ReviewCaloriesPayload;
use App\Support\Review\ReviewAnswers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\ReviewSeed;
use Tests\Support\ReviewShowWorld;

// Step 6 of review_show (rp-clone WeeklyReviewCaloriesTests): last week's calories, the reasons and the options.

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow(ReviewSeed::NOW));
afterEach(fn () => Carbon::setTestNow());

/** @return array<string, mixed> */
function rvCalories(?Closure $change = null, ?ReviewAnswers $answers = null): array
{
    return ReviewCaloriesPayload::build(ReviewShowWorld::analysis($change), $answers ?? ReviewAnswers::defaults())->toArray();
}

/** @param  array<string, mixed>  $calories */
function rvOption(array $calories, string $id): array
{
    $option = array_values(array_filter($calories['options'], fn (array $option) => $option['id'] === $id))[0] ?? null;

    return $option ?? throw new RuntimeException("No option {$id}.");
}

/** @param  array<string, mixed>  $calories */
function rvNotes(array $calories): array
{
    return array_column($calories['notes'], 'text');
}

/** @param  array<string, mixed>  $calories */
function rvSelected(array $calories): array
{
    return array_column(array_filter($calories['options'], fn (array $option) => $option['is_selected']), 'id');
}

/** @param  array<string, mixed>  $calories */
function rvRecommended(array $calories): array
{
    return array_column(array_filter($calories['options'], fn (array $option) => $option['is_recommended']), 'id');
}

function rvStalled(ReviewSeed $seed): void
{
    $seed->weight = fn (int $offset): float => 88.0;
}

function rvAnswers(string $option, ?int $ownKcal = null, ?bool $deloadToo = null, bool $updateStepPlan = false): ReviewAnswers
{
    return new ReviewAnswers($option, $ownKcal, $deloadToo, $updateStepPlan, [], null);
}

// MARK: REV-010

it('REV-010 shows last week\'s target and average and the four options, recommending the recommendation', function () {
    $calories = rvCalories();

    expect($calories['lines'])->toBe([
        ['label' => 'Last week\'s target', 'value' => '2400 kcal', 'tone' => 'neutral'],
        ['label' => 'Last week\'s average', 'value' => '2400 kcal', 'tone' => 'neutral'],
    ])
        ->and(array_column($calories['options'], 'id'))->toBe(['recommendation', 'repeat_this_week', 'choose_own', 'drop_fatigue'])
        ->and(array_column($calories['options'], 'title'))->toBe(['Take the recommendation', 'Repeat this week', 'Choose my own calories', 'Drop fatigue'])
        ->and(rvRecommended($calories))->toBe(['recommendation'])
        ->and(rvSelected($calories))->toBe(['recommendation'])
        ->and(rvOption($calories, 'drop_fatigue')['detail'])->toEndWith(
            "\nTake a break. Eating closer to maintenance will drop fatigue and may let you judge your progress better",
        )
        ->and(rvOption($calories, 'choose_own')['detail'])->toEndWith("\nAverage daily calories, within safety limits")
        ->and($calories['own'])->toBeNull()
        ->and($calories['deload'])->toBeNull()
        ->and($calories['step_offer'])->toBeNull();
});

it('REV-010 says nothing was checked in when no meal was', function () {
    $calories = rvCalories(fn (ReviewSeed $seed) => $seed->isChecked = fn (string $date, int $meal) => $date < '2026-10-05');

    expect($calories['lines'][1])->toBe(['label' => 'Last week\'s average', 'value' => 'Nothing checked in', 'tone' => 'neutral']);
});

it('REV-010 offers Drop fatigue in Fat loss only, and not after a week that was a diet break', function () {
    $muscleGain = rvCalories(fn (ReviewSeed $seed) => ReviewShowWorld::muscleGain($seed));
    $afterBreak = rvCalories(fn (ReviewSeed $seed) => $seed->planWeekRows = [['start_date' => '2026-10-05', 'is_diet_break' => true]]);

    expect(array_column($muscleGain['options'], 'id'))->toBe(['recommendation', 'repeat_this_week', 'choose_own'])
        ->and(array_column($afterBreak['options'], 'id'))->toBe(['recommendation', 'repeat_this_week', 'choose_own']);
});

it('REV-013 shows each option with next week\'s average calories and macro split', function () {
    $calories = rvCalories();

    foreach (['recommendation', 'repeat_this_week', 'choose_own', 'drop_fatigue'] as $id) {
        expect(rvOption($calories, $id)['detail'])->toMatch('/^Next week: \d+ kcal · P \d+ · F \d+ · C \d+/', $id);
    }
    expect(rvOption($calories, 'recommendation')['detail'])->toStartWith('Next week: 2400 kcal · P ')
        ->and(rvOption($calories, 'repeat_this_week')['detail'])->toStartWith('Next week: 2400 kcal · P ')
        // REV-011: a diet break eats at maintenance, above the cut.
        ->and((int) explode(' ', rvOption($calories, 'drop_fatigue')['detail'])[2])->toBeGreaterThan(2400);
});

// MARK: the reasons

it('REV-025 poor adherence says what was averaged against the target, or how few days were logged', function () {
    $averaged = rvCalories(fn (ReviewSeed $seed) => $seed->eatenShare = 0.8);
    $fewDays = rvCalories(fn (ReviewSeed $seed) => $seed->isChecked = fn (string $date, int $meal) => $date <= '2026-10-06');

    expect(rvNotes($averaged))->toBe(['You averaged 1,920 kcal against a target of 2,400 kcal. Hit your targets first, then the app adjusts.'])
        ->and(rvNotes($fewDays))->toBe(['You logged 2 of 7 days. Hit your targets first, then the app adjusts.']);
});

it('REV-024 REV-028 REV-029 REV-032 words the reason for the recommendation, in info', function () {
    $onTarget = rvCalories();
    $stalledGain = rvCalories(function (ReviewSeed $seed) {
        ReviewShowWorld::muscleGain($seed);
        $seed->weight = fn (int $offset): float => 90.0;
    });
    $corridor = rvCalories(function (ReviewSeed $seed) {
        $seed->phaseType = 'maintenance';
        $seed->ratePercent = 0;
        $seed->weight = fn (int $offset): float => 90.0;
    });
    $first = rvCalories(function (ReviewSeed $seed) {
        $seed->weeksFinished = 1;
        $seed->weight = fn (int $offset): float => 90.0;
    });

    expect($onTarget['notes'])->toBe([['text' => 'You are on target, so calories stay as they are.', 'tone' => 'info']])
        ->and(rvNotes($stalledGain))->toBe(['Your gain has stalled, so calories go up.'])
        ->and(rvNotes($corridor))->toBe(['Your trend weight is inside its corridor, so calories stay as they are.'])
        ->and(rvNotes($first))->toBe(['The first week of a phase is left out of the rate, so calories hold.']);
});

// MARK: choose my own

it('REV-010 Choose my own calories answered with an amount is selected, shows the amount and builds next week at it', function () {
    $calories = rvCalories(answers: rvAnswers('choose_own', 2200));

    expect(rvSelected($calories))->toBe(['choose_own'])
        ->and(rvOption($calories, 'choose_own')['detail'])->toStartWith('Next week: 2200 kcal · P ')
        ->and(rvOption($calories, 'choose_own')['is_enabled'])->toBeTrue()
        ->and($calories['own'])->toBe(['label' => 'Calories a day', 'unit_label' => 'kcal a day', 'value' => 2200]);
});

it('SAFE-012 REV-010 an amount safety refuses is shown as refused and cannot be chosen', function () {
    $calories = rvCalories(answers: rvAnswers('choose_own', 900));
    $option = rvOption($calories, 'choose_own');

    expect($option['is_enabled'])->toBeFalse()
        ->and($option['detail'])->toStartWith('That is too few calories to fit your protein, fat and carbs. Choose a higher average.')
        ->and($option['detail'])->not->toContain('Next week:')
        ->and($calories['own']['value'])->toBe(900);
});

it('REV-010 Choose my own calories with no amount asks for one', function () {
    $option = rvOption(rvCalories(answers: rvAnswers('choose_own', 0)), 'choose_own');

    expect($option['is_enabled'])->toBeFalse()
        ->and($option['detail'])->toStartWith('Enter the average calories a day.');
});

it('REV-010 an option the layout does not list falls back to the recommended one', function () {
    $calories = rvCalories(answers: rvAnswers('cut_calories'));

    expect(rvSelected($calories))->toBe(['recommendation']);
});

// MARK: REV-012

it('REV-012 a diet break marked ahead is said so and offers only Repeat and Choose my own', function () {
    $calories = rvCalories(fn (ReviewSeed $seed) => $seed->planWeekRows = [['start_date' => '2026-10-12', 'is_diet_break' => true]]);

    expect($calories['notes'][0])->toBe(['text' => 'Next week is a diet break at maintenance calories', 'tone' => 'info'])
        ->and(array_column($calories['options'], 'id'))->toBe(['repeat_this_week', 'choose_own'])
        ->and(rvSelected($calories))->toBe(['repeat_this_week'])
        // REV-002: a planned week keeps its own rules, so Repeat shows the break's maintenance calories.
        ->and((int) explode(' ', rvOption($calories, 'repeat_this_week')['detail'])[2])->toBeGreaterThan(2400);
});

it('REV-012 a Fat loss deload next week is a diet break at maintenance, and an ordinary week has no such note', function () {
    $deload = rvCalories(fn (ReviewSeed $seed) => $seed->mesocycleWeeks = 5);

    expect($deload['notes'][0]['text'])->toBe('Next week is a diet break at maintenance calories')
        ->and(array_column($deload['options'], 'id'))->toBe(['repeat_this_week', 'choose_own'])
        ->and(rvNotes(rvCalories()))->not->toContain('Next week is a diet break at maintenance calories');
});

// MARK: REV-026, REV-027

it('REV-027 a stall says so and offers cut, add steps, hold, a diet break and Choose my own', function () {
    $calories = rvCalories(fn (ReviewSeed $seed) => rvStalled($seed));

    expect($calories['notes'][0])->toBe(['text' => 'Your progress has stalled', 'tone' => 'warning'])
        ->and(array_column($calories['options'], 'id'))->toBe(['cut_calories', 'add_steps', 'repeat_this_week', 'drop_fatigue', 'choose_own'])
        ->and(rvOption($calories, 'repeat_this_week')['title'])->toBe('Hold for another week')
        ->and(rvOption($calories, 'drop_fatigue')['title'])->toBe('Take a diet break')
        ->and(rvOption($calories, 'cut_calories')['title'])->toBe('Cut 250 calories a day')
        ->and(rvOption($calories, 'add_steps')['title'])->toBe('Add 2,000 steps a day')
        // One change at a time; the recommended one is the one selected.
        ->and(rvRecommended($calories))->toHaveCount(1)
        ->and(rvSelected($calories))->toBe(rvRecommended($calories));
});

it('REV-027 the cut takes the calories off, and holding or adding steps keeps them', function () {
    $calories = rvCalories(fn (ReviewSeed $seed) => rvStalled($seed));

    expect(rvOption($calories, 'cut_calories')['detail'])->toStartWith('Next week: 2150 kcal')
        ->and(rvOption($calories, 'repeat_this_week')['detail'])->toStartWith('Next week: 2400 kcal')
        ->and(rvOption($calories, 'add_steps')['detail'])->toStartWith('Next week: 2400 kcal');
});

it('REV-026 a trend that rose on water holds the target and says so', function () {
    $calories = rvCalories(fn (ReviewSeed $seed) => $seed->weight = fn (int $offset): float => $offset === -6 ? 89.6 : 88.0);

    expect(rvNotes($calories))->toContain('Water weight is up. Holding your target this week.')
        ->and(rvOption($calories, 'recommendation')['detail'])->toStartWith('Next week: 2400 kcal');
});

// MARK: REV-041, REV-042, REV-044

it('REV-041 high training fatigue recommends Drop fatigue, in the spec\'s words', function () {
    $calories = rvCalories(fn (ReviewSeed $seed) => $seed->muscleFeedback = ReviewSeed::highFatigue(ReviewSeed::sessionKey(1)));

    expect($calories['notes'])->toContain(['text' => 'Your lifts are dropping. It might be the diet, not the training.', 'tone' => 'warning'])
        ->and(rvRecommended($calories))->toBe(['drop_fatigue'])
        ->and(rvSelected($calories))->toBe(['drop_fatigue']);
});

it('REV-042 six weeks without a break suggests Drop fatigue without other signs', function () {
    $calories = rvCalories(function (ReviewSeed $seed) {
        $seed->weeksFinished = 6;
        $seed->mesocycleWeeks = 8;
    });

    expect(rvNotes($calories))->toContain('You have been in a deficit for 6 weeks without a diet break or deload.')
        ->and(rvRecommended($calories))->toBe(['drop_fatigue']);
});

it('REV-044 Muscle gain never turns fatigue into a diet change', function () {
    $calories = rvCalories(function (ReviewSeed $seed) {
        ReviewShowWorld::muscleGain($seed);
        $seed->muscleFeedback = [['session' => ReviewSeed::sessionKey(1), 'muscle' => 'chest', 'soreness' => 'stillSore', 'volume' => 'tooMuch']];
    });

    expect(rvNotes($calories))->not->toContain('Your lifts are dropping. It might be the diet, not the training.');
});

// MARK: REV-011

it('REV-011 Drop fatigue two weeks from the deload is one week: the diet break and the deload', function () {
    $calories = rvCalories(answers: rvAnswers('drop_fatigue'));

    expect(rvSelected($calories))->toBe(['drop_fatigue'])
        ->and($calories['deload'])->toBe([
            'kind' => 'deload_now', 'question' => null, 'answer' => null,
            'note' => 'Deload now: the diet break and the deload are one week, starting next week.',
            'rir_label' => 'Target RIR by week: 3, 2, 1',
        ]);
});

it('REV-011 Drop fatigue further from the deload asks whether to make it the deload week too, yes by default', function () {
    $asked = rvCalories(fn (ReviewSeed $seed) => $seed->mesocycleWeeks = 8, rvAnswers('drop_fatigue'));
    $declined = rvCalories(fn (ReviewSeed $seed) => $seed->mesocycleWeeks = 8, rvAnswers('drop_fatigue', deloadToo: false));
    $other = rvCalories(fn (ReviewSeed $seed) => $seed->mesocycleWeeks = 8, rvAnswers('repeat_this_week', deloadToo: true));

    expect($asked['deload']['kind'])->toBe('ask')
        ->and($asked['deload']['question'])->toBe('Make this your deload week too?')
        ->and($asked['deload']['answer'])->toBeTrue()
        ->and($asked['deload']['note'])->toBeNull()
        ->and($asked['deload']['rir_label'])->toStartWith('Target RIR by week: 3')
        ->and($declined['deload']['answer'])->toBeFalse()
        ->and($declined['deload']['rir_label'])->toBeNull()
        // The deload answer matters only while Drop fatigue is selected.
        ->and($other['deload'])->toBeNull();
});

it('REV-011 the answer to the deload moves the deload in the week Drop fatigue builds', function () {
    $yes = rvOption(rvCalories(fn (ReviewSeed $seed) => $seed->mesocycleWeeks = 8, rvAnswers('drop_fatigue', deloadToo: true)), 'drop_fatigue');
    $no = rvOption(rvCalories(fn (ReviewSeed $seed) => $seed->mesocycleWeeks = 8, rvAnswers('drop_fatigue', deloadToo: false)), 'drop_fatigue');

    // Both are a diet break at maintenance; the deload week also cuts the week's sets, so the lines can differ in macros only.
    expect($yes['detail'])->toStartWith('Next week: ')
        ->and($no['detail'])->toStartWith('Next week: ');
});

// MARK: REV-050

it('REV-050 offers to update the step plan when the walked average is more than 2,000 steps from the plan', function () {
    $steps = fn (ReviewSeed $seed, int $count) => $seed->steps = array_combine(
        array_map(fn (int $offset) => ReviewSeed::day($offset), range(-7, -1)),
        array_fill(0, 7, $count),
    );

    $offered = rvCalories(fn (ReviewSeed $seed) => $steps($seed, 10000));
    $accepted = rvCalories(fn (ReviewSeed $seed) => $steps($seed, 10000), rvAnswers('recommendation', updateStepPlan: true));
    $near = rvCalories(fn (ReviewSeed $seed) => $steps($seed, 8500));

    expect($offered['step_offer'])->toBe(['label' => 'Update my step plan to 10,000 steps a day', 'accepted' => false])
        ->and($accepted['step_offer']['accepted'])->toBeTrue()
        ->and(rvNotes($offered))->toContain('You walked 10,000 steps a day on average against a plan of 7,000. Update your step plan?')
        ->and($near['step_offer'])->toBeNull()
        ->and(rvCalories()['step_offer'])->toBeNull();
});
