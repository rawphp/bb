<?php

use App\Capabilities\Reads\Onboarding\OnboardingShowPayload;
use App\Models\OnboardingDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\ScreenFixture;
use Tests\Unit\Capabilities\Reads\Onboarding\OnboardingShowWorld as W;

// onboarding_show: what setup shows on each step, from a draft saved through App\Models\OnboardingDraft (ONB-002). The
// rules are the unit tests of app/Domain/Onboarding; these prove the payload maps them, one case for each screen
// fixture, with the clock fixed to the fixtures' Saturday 2026-10-10. A show never writes.
uses(RefreshDatabase::class);

beforeEach(fn () => W::freezeClock());
afterEach(fn () => Carbon::setTestNow());

/** @return array<string, mixed> */
function onboardingShown(User $user): array
{
    return OnboardingShowPayload::for($user)->toArray();
}

/**
 * The payload has the shape of the named screen fixture, and the output schema (one for every variant, as the schema
 * is onboarding_show.output.json).
 *
 * @param  array<mixed>  $shown
 */
function onboardingMatchesFixture(string $variant, array $shown): void
{
    assertMatchesScreenFixture('onboarding_show', $shown);
    expect(ScreenFixture::differences(ScreenFixture::load("onboarding_show.{$variant}"), $shown))->toBe([]);
}

/** @return list<string> */
function onboardingChosen(array $choices): array
{
    return array_values(array_map(fn (array $choice) => $choice['value'], array_filter($choices, fn (array $choice) => $choice['is_selected'])));
}

it('ONB-018 UI-007 shows the review, matching the onboarding_show fixture', function () {
    $world = W::sam(14);
    $shown = onboardingShown($world['user']);

    assertMatchesScreenFixture('onboarding_show', $shown);
    expect($shown['step'])->toMatchArray(['number' => 14, 'key' => 'review', 'counter' => 'Review', 'position' => 13, 'total' => 13, 'next_title' => 'Start my plan', 'can_continue' => true, 'continue_hint' => null, 'can_go_back' => true])
        ->and($shown['notices'])->toBe([])
        ->and(array_column($shown['review']['sections'], 'title'))->toBe(['Goal', 'Phase', 'About you', 'Steps', 'Training days', 'Meals', 'First mesocycle', 'Weigh-ins', 'Calories'])
        ->and($shown['review']['sections'][1]['rows'][3])->toBe(['label' => 'Target weight', 'value' => '160.5 lb', 'badges' => []])
        ->and($shown['review']['sections'][8]['rows'][0]['badges'])->toBe([['text' => 'Recommended', 'tone' => 'good'], ['text' => 'Selected', 'tone' => 'info']])
        ->and($shown['review']['note'])->toBe('Exact meal times and amounts can be fine-tuned after you start.');
});

it('ONB-004 shows step 2, about you, in the units the user chose, and what it still needs', function () {
    $world = W::sam(2, ['name' => '']);
    $shown = onboardingShown($world['user']);

    onboardingMatchesFixture('about_you', $shown);
    expect($shown['step'])->toMatchArray(['number' => 2, 'key' => 'about_you', 'counter' => 'Step 2 of 13', 'can_continue' => false, 'continue_hint' => 'Fill in every field to continue.'])
        ->and(onboardingChosen($shown['about_you']['sex_options']))->toBe(['female'])
        ->and($shown['about_you']['birth_date'])->toBe(['date' => '1990-04-09', 'text' => 'Apr 9, 1990', 'initial' => '1990-04-09', 'min' => null, 'max' => '2026-10-09', 'is_locked' => false])
        ->and($shown['about_you']['height'])->toMatchArray(['unit' => 'feetInches', 'cm' => 170.2, 'feet' => 5, 'inches' => 7.0, 'text' => '5 ft 7 in'])
        ->and($shown['about_you']['weight'])->toMatchArray(['unit' => 'lb', 'value' => 176.4, 'text' => '176.4 lb'])
        ->and(onboardingChosen($shown['about_you']['weight']['unit_options']))->toBe(['lb']);
});

it('SAFE-001 shows step 3, the screening, with the measured rows locked and ticked by step 2', function () {
    $world = W::sam(3);
    $shown = onboardingShown($world['user']);

    onboardingMatchesFixture('screening', $shown);
    $rows = $shown['screening']['rows'];
    expect($shown['step']['counter'])->toBe('Step 3 of 13')
        ->and($shown['screening']['prompt'])->toBe('Tick anything that applies to you.')
        ->and($shown['screening']['clearance_note'])->toBe(['text' => 'Get medical clearance from your doctor before starting any diet.', 'tone' => 'warning'])
        ->and(array_column($rows, 'is_locked'))->toBe([true, true, true, true, false, false, false, false, false, false, false, false, false])
        ->and(array_sum(array_column($rows, 'is_on')))->toBe(0)
        ->and($rows[5])->toMatchArray(['item' => 'tryingToConceive', 'detail' => 'Only Fat loss is unavailable.']);
});

it('SAFE-001 ticks the items the user ticked and the ones step 2 works out', function () {
    $world = W::sam(3, ['screening' => ['gallbladderDisease'], 'weight_kg' => 170.0]);
    $rows = array_column(onboardingShown($world['user'])['screening']['rows'], null, 'item');

    expect($rows['gallbladderDisease']['is_on'])->toBeTrue()
        ->and($rows['weightOverLimit'])->toMatchArray(['is_on' => true, 'is_locked' => true, 'detail' => 'From your answers on step 2'])
        ->and($rows['diabetes']['is_on'])->toBeFalse();
});

it('SAFE-013 shows step 4, the weight-loss drug question, with its answer', function () {
    $world = W::sam(4);
    $shown = onboardingShown($world['user']);

    onboardingMatchesFixture('glp1', $shown);
    expect($shown['step']['key'])->toBe('glp1')
        ->and($shown['glp1'])->toBe(['question' => 'Do you take a GLP-1 or similar weight-loss drug?', 'detail' => 'For example semaglutide, tirzepatide or retatrutide.', 'answer' => false]);
});

it('ONB-007 shows step 5, the body-fat photos for the user\'s sex, with the pick', function () {
    $world = W::sam(5);
    $shown = onboardingShown($world['user']);

    onboardingMatchesFixture('body_fat', $shown);
    expect($shown['body_fat']['sex'])->toBe('female')
        ->and(array_column($shown['body_fat']['options'], 'title'))->toBe(['under 20%', '20-27%', '27-35%', 'over 35%'])
        ->and(onboardingChosen($shown['body_fat']['options']))->toBe(['low'])
        ->and($shown['body_fat']['help'])->toContain('smart scale');
});

it('ONB-008 ONB-009 shows step 6, the rate and length, with the dates and target it works out', function () {
    $world = W::sam(6);
    $shown = onboardingShown($world['user']);
    $rate = $shown['rate_length'];

    onboardingMatchesFixture('rate_length', $shown);
    expect($shown['step'])->toMatchArray(['number' => 6, 'counter' => 'Step 6 of 13', 'next_title' => 'Continue'])
        ->and(onboardingChosen($rate['options']))->toBe(['Highest success'])
        ->and(array_column($rate['options'], 'detail'))->toBe(['Lose 0.75% of body weight per week', 'Lose 0.5% of body weight per week', ''])
        ->and($rate['is_custom'])->toBeFalse()
        ->and($rate['custom_rate'])->toBe(['value' => 0.75, 'min' => 0.25, 'max' => 1.0, 'step' => 0.025, 'text' => 'Lose 0.75% of body weight per week'])
        ->and($rate['weeks'])->toBe(['value' => 12.0, 'min' => 2.0, 'max' => 16.0, 'step' => 1.0, 'text' => '12 weeks'])
        ->and($rate['recommended_text'])->toBe('Recommended: 6 to 12 weeks')
        ->and($rate['start_date'])->toBe(['date' => '2026-10-11', 'text' => 'Oct 11, 2026', 'initial' => '2026-10-11', 'min' => '2026-10-11', 'max' => null, 'is_locked' => false])
        ->and($rate['last_day']['date'])->toBe('2027-01-02')
        ->and($rate['target_weight'])->toBe(['value' => 160.5, 'unit' => 'lb', 'text' => '160.5 lb', 'can_edit' => true, 'note' => '']);
});

it('ONB-009 SAFE-005 shows the fat-loss cautions of step 6 as its notices, and the custom rate', function () {
    $world = W::sam(6, ['rate_percent_per_week' => -1.0, 'custom_rate' => true, 'weeks' => 16, 'weight_unit' => 'kg', 'height_unit' => 'cm',
        'experience' => null, 'training_days' => [], 'training_plan' => null, 'template_id' => null]);
    $shown = onboardingShown($world['user']);

    onboardingMatchesFixture('warning', $shown);
    expect($shown['notices'])->toBe([
        ['text' => 'Fat-loss phases over 12 weeks are hard to sustain.', 'tone' => 'warning'],
        ['text' => 'This plan loses more than 10% of your starting weight. Consider a slower rate or a shorter phase.', 'tone' => 'warning'],
    ])->and($shown['rate_length']['is_custom'])->toBeTrue()
        ->and(onboardingChosen($shown['rate_length']['options']))->toBe(['Custom'])
        ->and($shown['rate_length']['custom_rate'])->toMatchArray(['value' => 1.0, 'text' => 'Lose 1% of body weight per week'])
        ->and($shown['rate_length']['target_weight'])->toMatchArray(['value' => 67.2, 'unit' => 'kg', 'text' => '67.2 kg'])
        ->and($shown['review']['sections'])->toBe([]);
});

it('ONB-010 shows step 7, the experience levels with their mesocycle lengths and the pick', function () {
    $world = W::sam(7);
    $shown = onboardingShown($world['user']);

    onboardingMatchesFixture('experience', $shown);
    expect(onboardingChosen($shown['experience']['options']))->toBe(['intermediate'])
        ->and(array_column($shown['experience']['options'], 'detail'))->toBe([
            'Under 1 year of consistent lifting. Mesocycles start at 6 weeks.',
            '1 to 4 years. Mesocycles start at 5 weeks.',
            'Over 4 years. Mesocycles start at 5 weeks.',
        ]);
});

it('ONB-011 shows step 8, all seven weekdays with the training days on and their times', function () {
    $world = W::sam(8, ['training_days' => [['weekday' => 2, 'start_minutes' => 390, 'length_minutes' => 75]]]);
    $shown = onboardingShown($world['user']);

    onboardingMatchesFixture('training_days', $shown);
    $days = $shown['training_days']['days'];
    expect($shown['training_days']['help'])->toBe('2 to 4 days you can repeat every week beat 5 or 6 you will miss.')
        ->and(array_column($days, 'name'))->toBe(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'])
        ->and(array_column($days, 'is_on'))->toBe([false, true, false, false, false, false, false])
        ->and($days[1])->toMatchArray(['full_name' => 'Tuesday', 'start' => ['minutes' => 390, 'text' => '6:30 AM'], 'length_minutes' => 75, 'length_text' => 'Length: 75 min'])
        ->and($days[0])->toMatchArray(['start' => ['minutes' => 1080, 'text' => '6:00 PM'], 'length_minutes' => 60])
        ->and($shown['training_days'])->toMatchArray(['length_min' => 15, 'length_max' => 300, 'length_step' => 15]);
});

it('ONB-012 shows step 9, the templates that fit with the recommendation selected and badged', function () {
    $world = W::sam(9, ['training_plan' => null, 'template_id' => null]);
    $shown = onboardingShown($world['user']);
    $options = $shown['training_plan']['options'];

    onboardingMatchesFixture('training_plan', $shown);
    expect(array_column($options, 'value'))->toBe(["template:{$world['full']}", "template:{$world['upper']}", 'meso_builder', 'later'])
        ->and(onboardingChosen($options))->toBe(["template:{$world['full']}"])
        ->and($options[0])->toMatchArray(['title' => 'Full Body 3-Day Dumbbells', 'detail' => '3 days a week · Full body', 'is_recommended' => true])
        ->and($options[1]['is_recommended'])->toBeFalse()
        ->and($shown['training_plan']['note'])->toBe('Templates that fit your 3 training days, sex and goal.')
        ->and($shown['step']['can_continue'])->toBeTrue();
});

it('ONB-012 shows the user\'s own pick, the Meso Builder or building later as the selection', function () {
    $world = W::sam(9, ['template_id' => null]);
    $picked = fn (array $changes) => onboardingChosen(onboardingShown(W::sam(9, $changes)['user'])['training_plan']['options']);

    expect($picked(['template_id' => null, 'training_plan' => 'template']))->toHaveCount(1)
        ->and($picked(['training_plan' => 'later']))->toBe(['later'])
        ->and($picked(['training_plan' => 'meso_builder']))->toBe(['meso_builder']);
    expect(onboardingChosen(onboardingShown($world['user'])['training_plan']['options']))->toHaveCount(1);
});

it('ONB-012 says why there are no templates when the days have none, and offers the other two plans', function () {
    $world = W::sam(9, ['training_days' => [['weekday' => 1, 'start_minutes' => 1080, 'length_minutes' => 60]], 'training_plan' => null, 'template_id' => null]);
    $shown = onboardingShown($world['user']);

    expect(array_column($shown['training_plan']['options'], 'value'))->toBe(['meso_builder', 'later'])
        ->and($shown['training_plan']['note'])->toBe('Templates are made for 2 to 6 training days. Use the Meso Builder, or build your plan later.')
        ->and($shown['step']['can_continue'])->toBeFalse()
        ->and($shown['step']['continue_hint'])->toBe('Pick a training plan to continue.');
});

it('ONB-013 shows step 10, the step ranges as weekday and weekend groups and a day each', function () {
    $world = W::sam(10);
    $shown = onboardingShown($world['user']);

    onboardingMatchesFixture('steps', $shown);
    $steps = $shown['steps'];
    expect($steps['groups'][0])->toBe(['key' => 'weekdays', 'title' => 'Weekdays', 'weekdays' => [1, 2, 3, 4, 5], 'is_mixed' => false, 'min' => 5000, 'max' => 8000, 'text' => '5,000 to 8,000 steps'])
        ->and($steps['groups'][1])->toMatchArray(['key' => 'weekends', 'min' => 8000, 'max' => 10000, 'text' => '8,000 to 10,000 steps'])
        ->and($steps['presets'][5])->toBe(['min' => 8000, 'max' => 13000, 'title' => '8k to 13k'])
        ->and($steps['days'][6])->toBe(['weekday' => 7, 'full_name' => 'Sunday', 'min' => 8000, 'max' => 10000, 'text' => '8,000 to 10,000 steps'])
        ->and($steps)->toMatchArray(['increment' => 500, 'max_steps' => 40000]);
});

it('ONB-013 marks a group whose days differ as mixed', function () {
    $world = W::sam(10, ['step_ranges' => [['weekday' => 3, 'min' => 8000, 'max' => 10000]]]);
    $group = onboardingShown($world['user'])['steps']['groups'][0];

    expect($group)->toMatchArray(['key' => 'weekdays', 'is_mixed' => true, 'min' => 0, 'max' => 0]);
});

it('SAFE-015 warns, without blocking, about a step target over 12,000 in a fat-loss phase', function () {
    $world = W::sam(10, ['step_ranges' => [['weekday' => 6, 'min' => 8000, 'max' => 13000]]]);
    $shown = onboardingShown($world['user']);

    expect($shown['notices'])->toHaveCount(1)
        ->and($shown['notices'][0]['tone'])->toBe('warning')
        ->and($shown['step']['can_continue'])->toBeTrue();
});

it('ONB-014 shows step 11, the eating window, the meal count and the busy periods', function () {
    $world = W::sam(11);
    $shown = onboardingShown($world['user']);

    onboardingMatchesFixture('meals', $shown);
    expect($shown['meals']['first_meal'])->toBe(['minutes' => 420, 'text' => '7:00 AM'])
        ->and($shown['meals']['last_meal'])->toBe(['minutes' => 1200, 'text' => '8:00 PM'])
        ->and($shown['meals']['window_note'])->toBe('This window applies to every day.')
        ->and($shown['meals']['meal_count'])->toBe(['value' => 5.0, 'min' => 2.0, 'max' => 6.0, 'step' => 1.0, 'text' => 'Meals a day: 5'])
        ->and($shown['meals']['busy_periods'])->toBe([[
            'id' => '01JBX0Q5ZK3J7Y8T6V2N4M1C9D', 'label' => 'Commute', 'start' => ['minutes' => 480, 'text' => '8:00 AM'],
            'end' => ['minutes' => 540, 'text' => '9:00 AM'], 'weekdays' => [1, 2, 3, 4, 5], 'weekdays_text' => 'Mon to Fri',
        ]]);
});

it('ONB-014 stops step 11 when the meals do not fit the window', function () {
    $world = W::sam(11, ['eating_start_minutes' => 480, 'eating_end_minutes' => 540, 'meal_count' => 6]);
    $step = onboardingShown($world['user'])['step'];

    expect($step['can_continue'])->toBeFalse()
        ->and($step['continue_hint'])->toBe("The eating window can't hold that many meals. Widen it, remove a busy period or have fewer meals.");
});

it('ONB-015 ONB-016 shows step 12, the weigh-in days with their time and the review day', function () {
    $world = W::sam(12);
    $shown = onboardingShown($world['user']);
    $days = $shown['weigh_ins']['days'];

    onboardingMatchesFixture('weigh_ins', $shown);
    expect(array_column($days, 'is_on'))->toBe([true, false, true, false, true, false, false])
        ->and($days[0]['time'])->toBe(['minutes' => 420, 'text' => '7:00 AM'])
        ->and($shown['weigh_ins']['hint'])->toBe('We recommend weighing in on 4 or more days a week for a clearer trend.')
        ->and(array_column($shown['weigh_ins']['review_days'], 'value'))->toBe(['1', '2', '3', '4', '5', '6', '7'])
        ->and(onboardingChosen($shown['weigh_ins']['review_days']))->toBe(['6']);
});

it('ONB-015 needs two weigh-in days to continue and stops recommending at four', function () {
    $one = onboardingShown(W::sam(12, ['weigh_ins' => [['weekday' => 2, 'minutes' => 420]]])['user']);
    $four = onboardingShown(W::sam(12, ['weigh_ins' => array_map(fn (int $weekday) => ['weekday' => $weekday, 'minutes' => 420], [1, 2, 3, 4]), 'review_weekday' => 7])['user']);

    expect($one['step'])->toMatchArray(['can_continue' => false, 'continue_hint' => 'Too few weigh-ins to continue'])
        ->and($four['weigh_ins']['hint'])->toBe('')
        ->and(onboardingChosen($four['weigh_ins']['review_days']))->toBe(['7']);
});

it('ONB-017 shows step 13, the recommendation with its daily average and the week\'s split', function () {
    $world = W::sam(13);
    $shown = onboardingShown($world['user']);
    $calories = $shown['calories'];

    onboardingMatchesFixture('calories', $shown);
    expect($shown['step'])->toMatchArray(['number' => 13, 'counter' => 'Step 13 of 13', 'can_continue' => true])
        ->and(onboardingChosen($calories['options']))->toBe(['recommendation'])
        ->and($calories['options'][0]['detail'])->toMatch('/^[\d,]+ kcal a day$/')
        ->and($calories['options'][0]['badges'])->toBe([['text' => 'Recommended', 'tone' => 'good']])
        ->and(array_column($calories['week'], 'name'))->toBe(['Sat', 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri'])
        ->and($calories['week'][0]['kcal_text'])->toMatch('/^[\d,]+ kcal$/')
        ->and($calories)->toMatchArray(['own_kcal' => null, 'own_kcal_max' => 10000, 'own_unit' => 'kcal a day']);
});

it('ONB-017 shows the user\'s own average once chosen, and asks for one while it is empty', function () {
    $own = onboardingShown(W::sam(13, ['chooses_own_calories' => true, 'own_daily_kcal' => 2000])['user']);
    $empty = onboardingShown(W::sam(13, ['chooses_own_calories' => true])['user']);

    expect(onboardingChosen($own['calories']['options']))->toBe(['own'])
        ->and($own['calories']['own_kcal'])->toBe(2000)
        ->and($own['step']['can_continue'])->toBeTrue()
        ->and($empty['step'])->toMatchArray(['can_continue' => false, 'continue_hint' => 'Enter your average daily calories to continue.'])
        ->and($empty['calories']['week'])->toBe([]);
});

it('ONB-002 ONB-003 shows a user with no saved setup the defaults on step 1, and writes nothing', function () {
    $world = W::library();
    $shown = onboardingShown($world['user']);

    onboardingMatchesFixture('empty', $shown);
    expect($shown['step'])->toMatchArray(['number' => 1, 'key' => 'goal', 'counter' => 'Step 1 of 13', 'can_continue' => false,
        'continue_hint' => 'Pick a goal and answer the question to continue.', 'can_go_back' => false])
        ->and(onboardingChosen($shown['goal']['options']))->toBe([])
        ->and($shown['goal']['recent_diet']['answer'])->toBeNull()
        ->and($shown['about_you']['birth_date'])->toMatchArray(['date' => null, 'text' => '', 'initial' => '1996-10-10'])
        ->and($shown['about_you']['height'])->toMatchArray(['unit' => 'cm', 'cm' => null, 'feet' => null, 'text' => ''])
        ->and($shown['rate_length']['options'])->toBe([])
        ->and($shown['rate_length']['start_date'])->toMatchArray(['date' => null, 'initial' => '2026-10-11'])
        ->and($shown['training_plan']['options'])->toHaveCount(2)
        ->and($shown['weigh_ins']['days'][0]['is_on'])->toBeFalse()
        ->and($shown['review']['sections'])->toBe([])
        ->and(OnboardingDraft::query()->count())->toBe(0);
});

it('ONB-003 offers the three goals, with the pick selected and the help text', function () {
    $shown = onboardingShown(W::sam(1, ['goal' => 'muscleGain'])['user']);

    expect(array_column($shown['goal']['options'], 'title'))->toBe(['Fat loss', 'Muscle gain', 'Maintenance'])
        ->and(onboardingChosen($shown['goal']['options']))->toBe(['muscleGain'])
        ->and($shown['goal']['help'])->toBe('Want to lose fat and build muscle at the same time? Pick Fat loss. There is no separate recomp goal.');
});

it('ONB-021 advises starting with Maintenance when Fat loss follows a recent diet', function () {
    $recent = onboardingShown(W::sam(1, ['finished_diet_recently' => true])['user']);
    $none = onboardingShown(W::sam(1)['user']);

    expect($recent['goal']['advice'])->toMatchArray(['action_title' => 'Start with Maintenance', 'action_goal' => 'maintenance'])
        ->and($recent['goal']['advice']['text'])->toContain('Maintenance first')
        ->and($none['goal']['advice'])->toBeNull();
});

it('ONB-005 SAFE-001 turns Fat loss off on step 1 while trying to conceive, and says so in a notice', function () {
    $shown = onboardingShown(W::sam(1, ['goal' => null, 'screening' => ['tryingToConceive']])['user']);

    expect($shown['goal']['options'][0])->toMatchArray(['value' => 'fatLoss', 'is_enabled' => false,
        'detail' => "Fat loss isn't available while you're trying to conceive or in fertility treatment."])
        ->and($shown['goal']['options'][1]['is_enabled'])->toBeTrue()
        ->and($shown['notices'])->toBe([['text' => "Fat loss isn't available while you're trying to conceive or in fertility treatment. Pick Maintenance or Muscle gain.", 'tone' => 'warning']])
        ->and($shown['training_only'])->toBeFalse();
});

it('ONB-005 SAFE-003 counts a training-only setup in 7 steps and says nutrition targets are off', function () {
    $world = W::sam(7, ['goal' => 'muscleGain', 'screening' => ['diabetes'], 'uses_glp1' => null, 'body_fat_bucket' => null,
        'rate_percent_per_week' => null, 'weeks' => null, 'start_date' => '2026-10-11']);
    $shown = onboardingShown($world['user']);

    assertMatchesScreenFixture('onboarding_show', $shown);
    expect($shown['training_only'])->toBeTrue()
        ->and($shown['step'])->toMatchArray(['number' => 7, 'counter' => 'Step 4 of 7', 'position' => 4, 'total' => 7])
        ->and($shown['notices'])->toHaveCount(1)
        ->and($shown['notices'][0]['tone'])->toBe('info')
        ->and($shown['notices'][0]['text'])->toStartWith('Nutrition targets are turned off.')
        ->and($shown['notices'][0]['text'])->toContain('• ');
});

it('SAFE-003 a training-only review skips the nutrition sections, the phase edit link and the note', function () {
    $world = W::sam(14, ['goal' => 'muscleGain', 'screening' => ['diabetes'], 'uses_glp1' => null, 'body_fat_bucket' => null,
        'rate_percent_per_week' => null, 'weeks' => null, 'training_plan' => 'later', 'template_id' => null]);
    $review = onboardingShown($world['user'])['review'];
    $phase = $review['sections'][1];

    expect(array_column($review['sections'], 'title'))->toBe(['Goal', 'Phase', 'About you', 'Training days', 'First mesocycle', 'Weigh-ins'])
        ->and($phase['can_edit'])->toBeFalse()
        ->and($phase['edit_step'])->toBe(6)
        ->and($review['sections'][0]['can_edit'])->toBeTrue()
        ->and($review['note'])->toBe('');
});

it('ONB-002 never writes: showing a saved draft leaves the row as it was', function () {
    $world = W::sam(9);
    $before = OnboardingDraft::query()->first()->toArray();

    onboardingShown($world['user']);
    onboardingShown($world['user']);

    expect(OnboardingDraft::query()->count())->toBe(1)
        ->and(OnboardingDraft::query()->first()->toArray())->toBe($before);
});

it('ONB-002 shows each user their own setup', function () {
    $sam = W::sam(5);
    $other = User::factory()->create();

    expect(onboardingShown($other)['step']['number'])->toBe(1)
        ->and(onboardingShown($sam['user'])['step']['number'])->toBe(5);
});
