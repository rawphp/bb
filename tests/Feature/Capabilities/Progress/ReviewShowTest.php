<?php

use App\Models\User;
use App\Models\WeighIn;
use Illuminate\Support\Carbon;
use Tests\Support\ReviewSeed;
use Tests\Support\ScreenFixture;

// The HTTP seam of review_show and its screen fixtures. The rules are unit tests (tests/Unit/Capabilities/Reads/Review).

beforeEach(fn () => Carbon::setTestNow(ReviewSeed::NOW));
afterEach(fn () => Carbon::setTestNow());

/** @return array<string, mixed> */
function reviewShowFor(User $user): array
{
    return test()->actingAs($user)->postJson('/capabilities/review_show')->assertOk()->json('data');
}

it('REV-001 refuses review_show when signed out', function () {
    $this->postJson('/capabilities/review_show')->assertUnauthorized();
});

it('REV-001 REV-003 REV-010 shows a full review with the clock fixed, in the review_show fixture\'s shape', function () {
    $data = reviewShowFor((new ReviewSeed)->seed());

    assertMatchesScreenFixture('review_show', $data);
    expect($data['state'])->toBe('ready')
        ->and(array_column($data['steps'], 'key'))->toBe(['weigh_ins', 'meals', 'training', 'progress', 'goal', 'calories', 'preview'])
        ->and(collect(['weigh_ins', 'meals', 'training', 'progress', 'goal', 'calories', 'preview'])->every(fn (string $key) => $data[$key] !== null))->toBeTrue()
        ->and(array_column($data['calories']['options'], 'value'))->toBe(['recommendation', 'repeat_this_week', 'choose_own', 'drop_fatigue'])
        ->and($data['calories']['options'][0]['is_recommended'])->toBeTrue()
        ->and($data['calories']['options'][0]['is_selected'])->toBeTrue()
        ->and($data['can_save'])->toBeTrue();
});

it('REV-041 REV-011 opens with the recommended Drop fatigue selected while nothing is answered yet', function () {
    $seed = new ReviewSeed;
    $seed->muscleFeedback = ReviewSeed::highFatigue(ReviewSeed::sessionKey(1));

    $data = reviewShowFor($seed->seed());
    $options = collect($data['calories']['options'])->keyBy('value');

    assertMatchesScreenFixture('review_show', $data);
    expect($options['drop_fatigue']['is_recommended'])->toBeTrue()
        ->and($options['drop_fatigue']['is_selected'])->toBeTrue()
        ->and($options['recommendation']['is_selected'])->toBeFalse()
        ->and($data['calories']['deload'])->not->toBeNull()
        ->and($data['preview']['days'])->toHaveCount(7)
        ->and($data['can_save'])->toBeTrue();
});

it('REV-005 shows a training-only review in the training_only fixture\'s shape, with no calories or preview', function () {
    $seed = new ReviewSeed;
    $seed->trainingOnly = true;

    $data = reviewShowFor($seed->seed());

    assertMatchesScreenFixture('review_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('review_show.training_only'), $data))->toBe([])
        ->and(array_column($data['steps'], 'key'))->toBe(['weigh_ins', 'training', 'progress'])
        ->and([$data['meals'], $data['goal'], $data['calories'], $data['preview']])->each->toBeNull();
});

it('SCH-061 shows an away review in the away fixture\'s shape, with only the weigh-ins and the preview', function () {
    $seed = new ReviewSeed;
    $seed->planWeekRows = [['start_date' => '2026-10-05', 'is_away' => true, 'away_training' => 'pause', 'away_calories' => 'maintenance']];

    $data = reviewShowFor($seed->seed());

    assertMatchesScreenFixture('review_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('review_show.away'), $data))->toBe([])
        ->and(array_column($data['steps'], 'key'))->toBe(['weigh_ins', 'preview'])
        ->and([$data['meals'], $data['training'], $data['progress'], $data['goal'], $data['calories']])->each->toBeNull();
});

it('REV-001 shows nothing to review in the empty fixture\'s shape when no review is open', function () {
    $data = reviewShowFor(User::factory()->create());

    assertMatchesScreenFixture('review_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('review_show.empty'), $data))->toBe([])
        ->and($data['state'])->toBe('unavailable')
        ->and($data['message'])->toBe('There is no plan to review yet.')
        ->and($data['steps'])->toBe([])
        ->and($data['can_save'])->toBeFalse();
});

it('REV-003 two users with reviews in the same week never see each other\'s rows', function () {
    $first = new ReviewSeed;
    $first->weight = fn (int $offset): float => 89.0;
    $second = new ReviewSeed;
    $second->weight = fn (int $offset): float => 71.0;
    $second->isChecked = fn (string $date, int $meal) => $meal !== 1;
    $one = $first->seed();
    $two = $second->seed();

    $a = reviewShowFor($one);
    $b = reviewShowFor($two);
    $marks = fn (array $review) => array_merge(...array_map(fn (array $day) => $day['meals'], $review['meals']['days']));
    $open = fn (array $review) => array_values(array_filter($marks($review), fn (array $meal) => ! $meal['is_checked']));

    expect($a['weigh_ins']['rows'][0]['value_label'])->toBe('89.0 kg')
        ->and($b['weigh_ins']['rows'][0]['value_label'])->toBe('71.0 kg')
        ->and($open($a))->toBe([])
        ->and($open($b))->toHaveCount(7)
        ->and(array_intersect(array_column($marks($a), 'meal_id'), array_column($marks($b), 'meal_id')))->toBe([]);
});

/**
 * SET-012: a user in `$zone` who weighs in on Saturdays, reviewing on Monday 26 October the week of 19 to 25 October,
 * with two readings that week: Monday 19 at 07:00 UTC and 2026-10-23 21:05 UTC, which is Saturday 24 at 07:05 in
 * Brisbane and Friday 23 in UTC.
 */
function reviewLocalDayUser(string $zone): User
{
    $seed = new ReviewSeed;
    $seed->weeksFinished = 6;
    $seed->mesocycleWeeks = 10;
    $seed->weighInWeekdays = [6];
    $user = $seed->seed();
    $user->forceFill(['time_zone' => $zone])->save();
    foreach (['2026-10-19 07:00:00', '2026-10-23 21:05:00'] as $at) {
        WeighIn::factory()->for($user)->create(['weighed_at' => $at, 'weight_kg' => 86.0]);
    }
    Carbon::setTestNow('2026-10-26 09:00:00');

    return $user;
}

it('SET-012 REV-003 puts each of the week\'s weigh-ins on the user\'s local day', function (string $zone, array $rows) {
    $data = reviewShowFor(reviewLocalDayUser($zone));

    expect(array_map(fn (array $row) => [$row['date'], $row['value_label'], $row['is_missing']], $data['weigh_ins']['rows']))
        ->toBe($rows);
})->with([
    'Brisbane' => ['Australia/Brisbane', [['2026-10-19', '86.0 kg', false], ['2026-10-24', '86.0 kg', false]]],
    'UTC' => ['UTC', [['2026-10-19', '86.0 kg', false], ['2026-10-23', '86.0 kg', false], ['2026-10-24', '', true]]],
]);

it('SET-012 REV-001 opens the review when two weigh-ins fall in the week on the user\'s local day', function (string $zone, string $state) {
    $user = reviewLocalDayUser($zone);
    // Monday 19 October at 07:00 in Brisbane; Sunday the 18th in UTC.
    WeighIn::query()->where('user_id', $user->id)->where('weighed_at', '2026-10-19 07:00:00')->update(['weighed_at' => '2026-10-18 21:00:00']);

    expect(reviewShowFor($user)['state'])->toBe($state);
})->with([
    'Brisbane' => ['Australia/Brisbane', 'ready'],
    'UTC' => ['UTC', 'unavailable'],
]);
