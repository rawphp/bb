<?php

use App\Domain\Model\HeightUnit;
use App\Domain\Model\WeightUnit;
use App\Domain\Onboarding\BusyPeriodChoice;
use App\Domain\Onboarding\Draft;
use App\Domain\Onboarding\OnboardingCalories;
use App\Domain\Onboarding\OnboardingReview;
use App\Domain\Onboarding\OnboardingText;
use App\Domain\Onboarding\ReviewBlock;
use App\Domain\Onboarding\StepRangeChoice;
use Tests\Unit\Domain\Onboarding\OnboardingFixtures as F;

// The review screen (ONB-018, ONB-019), the read members of rp-clone's OnboardingModel+Review: one block per section
// of the plan, in the spec's order, each with the step its Edit link goes back to. The person weighs 80 kg and starts
// a 12-week fat-loss phase on Sunday 11 October, so with a Saturday review day the phase is plan weeks 1 to 12 and its
// last day is Friday 1 January.

function onboardingReview(Draft $draft): OnboardingReview
{
    return new OnboardingReview($draft, F::today(), F::catalogue());
}

/** @return array<string, ReviewBlock> the blocks by title */
function onboardingBlocks(Draft $draft): array
{
    $blocks = [];
    foreach (onboardingReview($draft)->blocks() as $block) {
        $blocks[$block->title] = $block;
    }

    return $blocks;
}

/** @return array<string, string> the rows of a block, label to value */
function onboardingRows(ReviewBlock $block): array
{
    return array_column(array_map(fn ($row) => [$row->label, $row->value], $block->rows), 1, 0);
}

it('ONB-018 lists every section of the plan in the spec\'s order, each with its Edit step', function () {
    $blocks = onboardingReview(F::atReview())->blocks();

    expect(array_map(fn (ReviewBlock $block) => $block->title, $blocks))->toBe([
        'Goal', 'Phase', 'About you', 'Steps', 'Training days', 'Meals', 'First mesocycle', 'Weigh-ins', 'Calories',
    ])->and(array_map(fn (ReviewBlock $block) => $block->editStep, $blocks))->toBe([1, 6, 2, 10, 8, 11, 9, 12, 13]);
});

it('ONB-018 shows the goal, phase length, dates, start and end weight and weekly rate', function () {
    $blocks = onboardingBlocks(F::atReview());

    expect(onboardingRows($blocks['Goal']))->toBe(['Goal' => 'Fat loss'])
        ->and(onboardingRows($blocks['Phase']))->toBe([
            'Length' => '12 weeks',
            'Dates' => 'Oct 11, 2026 to Jan 1, 2027',
            'Start weight' => '80 kg',
            'Target weight' => '72.8 kg',
            'Weekly rate' => '-0.75% of body weight',
        ]);
});

it('ONB-018 shows the stats: name, sex, date of birth, height, weight and body fat', function () {
    expect(onboardingRows(onboardingBlocks(F::atReview())['About you']))->toBe([
        'Name' => 'Sam', 'Sex' => 'Female', 'Date of birth' => 'Apr 9, 1990', 'Height' => '170 cm', 'Weight' => '80 kg',
        'Body fat' => '20-27%',
    ]);
});

it('ONB-018 shows weight and height in the unit the user chose', function () {
    $draft = F::atReview()->with(weightUnit: WeightUnit::Lb, heightUnit: HeightUnit::FeetInches);
    $rows = onboardingRows(onboardingBlocks($draft)['About you']);

    expect($rows['Weight'])->toBe('176.4 lb')
        ->and($rows['Height'])->toBe('5 ft 7 in')
        ->and(onboardingRows(onboardingBlocks($draft)['Phase'])['Target weight'])->toBe('160.5 lb');
});

it('ONB-018 joins neighbouring days with the same step range in one row', function () {
    $blocks = onboardingBlocks(F::atReview()->with(stepRanges: [new StepRangeChoice(3, 8_000, 13_000)]));

    expect(onboardingRows($blocks['Steps']))->toBe([
        'Mon and Tue' => '5,000 to 8,000', 'Wed' => '8,000 to 13,000', 'Thu and Fri' => '5,000 to 8,000', 'Sat and Sun' => '8,000 to 10,000',
    ]);
});

it('ONB-018 shows the training days with their times, and the meals with their busy periods', function () {
    $draft = F::atReview()->with(busyPeriods: [new BusyPeriodChoice('b1', 'Commute', 480, 540, [1, 2, 3, 4, 5])]);
    $blocks = onboardingBlocks($draft);

    expect(onboardingRows($blocks['Training days']))->toBe(['Mon' => '6:00 PM for 60 min', 'Wed' => '6:00 PM for 60 min', 'Fri' => '6:00 PM for 60 min'])
        ->and(onboardingRows($blocks['Meals']))->toBe([
            'Eating window' => '7:00 AM to 8:00 PM', 'Meals a day' => '5', 'Commute' => '8:00 AM to 9:00 AM, Mon to Fri',
        ]);
});

it('ONB-018 shows the first mesocycle: name, length, days and deload week', function () {
    expect(onboardingRows(onboardingBlocks(F::atReview())['First mesocycle']))->toBe([
        'Name' => 'Full body three', 'Length' => '5 weeks', 'Days' => 'Mon, Wed, Fri', 'Deload week' => 'Week 5',
    ]);
});

it('ONB-018 names the mesocycle as built later when no template is the plan', function () {
    $rows = onboardingRows(onboardingBlocks(F::atStep13()->with(step: 14))['First mesocycle']);

    expect($rows['Name'])->toBe('Built later');
});

it('ONB-018 shows the weigh-in days and times and the review day', function () {
    expect(onboardingRows(onboardingBlocks(F::atReview())['Weigh-ins']))->toBe([
        'Mon' => '7:00 AM', 'Wed' => '7:00 AM', 'Fri' => '7:00 AM', 'Review day' => 'Saturday',
    ]);
});

it('ONB-018 marks the recommendation as Recommended and Selected when it is the choice', function () {
    $draft = F::atReview();
    $average = (new OnboardingCalories($draft, F::today(), F::catalogue()))->recommended()->averageKcal;
    $rows = onboardingBlocks($draft)['Calories']->rows;

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->label)->toBe('RP recommendation')
        ->and($rows[0]->value)->toBe(OnboardingText::kcalText($average))
        ->and($rows[0]->badges)->toBe(['Recommended', 'Selected']);
});

it('ONB-018 shows the calorie target marked Selected next to the Recommended one when the user chose their own', function () {
    $rows = onboardingBlocks(F::atReview()->with(choosesOwnCalories: true, ownDailyKcal: 2000.0))['Calories']->rows;

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->badges)->toBe(['Recommended'])
        ->and($rows[1]->label)->toBe('Your choice')
        ->and($rows[1]->value)->toBe('2,000 kcal a day')
        ->and($rows[1]->badges)->toBe(['Selected']);
});

it('ONB-019 says exact meal times and amounts can be fine-tuned after starting', function () {
    expect(onboardingReview(F::atReview())->note())->toBe('Exact meal times and amounts can be fine-tuned after you start.');
});

it('SAFE-003 a training-only review leaves out the nutrition sections, the phase edit link and the note', function () {
    $review = onboardingReview(F::atTrainingOnlyReview());
    $blocks = onboardingBlocks(F::atTrainingOnlyReview());

    expect(array_keys($blocks))->toBe(['Goal', 'Phase', 'About you', 'Training days', 'First mesocycle', 'Weigh-ins'])
        ->and(onboardingRows($blocks['Goal']))->toBe(['Goal' => 'Muscle gain', 'Mode' => 'Training only'])
        ->and($blocks['Phase']->editStep)->toBeNull()
        ->and(array_keys(onboardingRows($blocks['Phase'])))->toBe(['Length', 'Dates'])
        ->and(array_keys(onboardingRows($blocks['About you'])))->not->toContain('Body fat')
        ->and($blocks['First mesocycle']->rows[0]->value)->toBe('Built later')
        ->and($blocks['First mesocycle']->rows[1]->value)->toBe('6 weeks')
        ->and($review->note())->toBe('');
});

it('ONB-018 has no sections until the answers the plan needs are in', function () {
    expect(onboardingReview(F::atStep3())->blocks())->toBe([])
        ->and(onboardingReview(F::atStep7())->blocks())->toBe([]);
});
