<?php

use App\Capabilities\Support\ReadKey;
use App\Models\ReviewAnswer;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\ReviewSeed;
use Tests\Support\ScreenFixture;

// The HTTP seam of review_answer, and review_show reading what it stored. The rules are
// tests/Unit/Actions/Review/AnswerReviewTest.php.

beforeEach(fn () => Carbon::setTestNow(ReviewSeed::NOW));
afterEach(fn () => Carbon::setTestNow());

/** @param  array<string, mixed>  $input */
function reviewAnswerAs(?User $user, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/review_answer', ['week_start' => '2026-10-05', 'option' => 'recommendation', ...$input], ['Idempotency-Key' => (string) Str::ulid()]);
}

it('REV-004 refuses review_answer when signed out', function () {
    reviewAnswerAs(null, [])->assertUnauthorized();
    expect(ReviewAnswer::query()->count())->toBe(0);
});

it('REV-004 answers 422 naming the field when an option is not a review option', function () {
    reviewAnswerAs((new ReviewSeed)->seed(), ['option' => 'skip'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'option');
    expect(ReviewAnswer::query()->count())->toBe(0);
});

it('REV-001 answers 422 for a week with no open review, and writes nothing', function () {
    reviewAnswerAs((new ReviewSeed)->seed(), ['week_start' => '2026-09-28'])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'There is no weekly review open for that week.');
    expect(ReviewAnswer::query()->count())->toBe(0);
});

it('REV-004 two answers for one week leave one row, holding the second answer, and say review_show changed', function () {
    $user = (new ReviewSeed)->seed();

    reviewAnswerAs($user, ['option' => 'choose_own', 'own_kcal' => 2300])->assertOk();
    reviewAnswerAs($user, ['option' => 'repeat_this_week', 'update_step_plan' => true])
        ->assertOk()
        ->assertJsonPath('data.invalidates', [ReadKey::for('review_show')])
        ->assertJsonPath('data.undo', null);

    $row = ReviewAnswer::query()->sole();
    expect($row->user_id)->toBe($user->id)
        ->and($row->option)->toBe('repeat_this_week')
        ->and($row->own_kcal)->toBeNull()
        ->and($row->update_step_plan)->toBeTrue();
});

it('REV-004 review_show reads the stored answer: Choose my own with a move, in the review_show fixture\'s shape', function () {
    $user = (new ReviewSeed)->seed();
    reviewAnswerAs($user, ['option' => 'choose_own', 'own_kcal' => 2300, 'moves' => [['from_index' => 0, 'to_index' => 5, 'kcal' => 100]]])->assertOk();

    $data = $this->actingAs($user)->postJson('/capabilities/review_show')->assertOk()->json('data');
    $selected = array_column(array_filter($data['calories']['options'], fn (array $option) => $option['is_selected']), 'value');

    assertMatchesScreenFixture('review_show', $data);
    expect(ScreenFixture::differences(ScreenFixture::load('review_show.choose_own'), $data))->toBe([])
        ->and($selected)->toBe(['choose_own'])
        ->and($data['calories']['own']['value'])->toBe(2300)
        ->and($data['preview']['moves'])->toBe([['from_index' => 0, 'to_index' => 5, 'kcal' => 100]])
        ->and($data['preview']['error'])->toBeNull()
        ->and($data['can_save'])->toBeTrue();
});

it('NUT-022 REV-004 a stored refusal comes back as the preview\'s error, with the days of the moves kept', function () {
    $user = (new ReviewSeed)->seed();
    reviewAnswerAs($user, ['moves' => [['from_index' => 0, 'to_index' => 5, 'kcal' => 500], ['from_index' => 0, 'to_index' => 5, 'kcal' => 500]]])->assertOk();

    $data = $this->actingAs($user)->postJson('/capabilities/review_show')->assertOk()->json('data');

    assertMatchesScreenFixture('review_show', $data);
    expect($data['preview']['error'])->toBe('A day stays between 80% and 125% of the week\'s average, and a day already past can\'t change.')
        ->and($data['preview']['moves'])->toBe([])
        ->and($data['preview']['days'])->toHaveCount(7)
        ->and($data['can_save'])->toBeTrue();
});

it('NUT-022 a stored option that will not build leaves review_show with no days and nothing to save', function () {
    $user = (new ReviewSeed)->seed();
    reviewAnswerAs($user, ['option' => 'choose_own', 'own_kcal' => 1000])->assertOk();

    $data = $this->actingAs($user)->postJson('/capabilities/review_show')->assertOk()->json('data');

    expect($data['preview']['days'])->toBe([])
        ->and($data['preview']['error'])->toBe('That is too few calories to fit your protein, fat and carbs. Choose a higher average.')
        ->and($data['can_save'])->toBeFalse();
});

it('REV-010 answers 422 naming own_kcal for an own amount of 0, and writes nothing', function () {
    reviewAnswerAs((new ReviewSeed)->seed(), ['option' => 'choose_own', 'own_kcal' => 0])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'own_kcal');
    expect(ReviewAnswer::query()->count())->toBe(0);
});
