<?php

use App\Models\User;
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
        ->and(array_column($data['calories']['options'], 'id'))->toBe(['recommendation', 'repeat_this_week', 'choose_own', 'drop_fatigue'])
        ->and($data['calories']['options'][0]['is_recommended'])->toBeTrue()
        ->and($data['calories']['options'][0]['is_selected'])->toBeTrue()
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

    expect($a['weigh_ins']['rows'][0]['value_label'])->toBe('89.0 kg')
        ->and($b['weigh_ins']['rows'][0]['value_label'])->toBe('71.0 kg')
        ->and($a['meals']['unchecked'])->toBe([])
        ->and($b['meals']['unchecked'])->toHaveCount(7)
        ->and(array_intersect(array_column($a['meals']['unchecked'], 'meal_id'), array_column($b['meals']['unchecked'], 'meal_id')))->toBe([]);
});
