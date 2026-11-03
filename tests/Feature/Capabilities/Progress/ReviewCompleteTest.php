<?php

use App\Capabilities\Support\ReadKey;
use App\Models\DayTarget;
use App\Models\Mesocycle;
use App\Models\PlanWeek;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\ReviewSeed;

// The HTTP seam of review_complete. The rules are tests/Unit/Actions/Review/CompleteReviewTest.php.

beforeEach(fn () => Carbon::setTestNow(ReviewSeed::NOW));
afterEach(fn () => Carbon::setTestNow());

/** @param  array<string, mixed>  $input */
function reviewCompleteAs(?User $user, array $input = ['week_start' => '2026-10-05']): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/review_complete', $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

it('REV-001 refuses review_complete when signed out', function () {
    reviewCompleteAs(null)->assertUnauthorized();
});

it('REV-001 answers 422 naming week_start when it is not a date', function () {
    reviewCompleteAs((new ReviewSeed)->seed(), ['week_start' => 'soon'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'week_start');
});

it('REV-001 answers 422 for a week with no open review, and writes nothing', function () {
    $user = (new ReviewSeed)->seed();

    reviewCompleteAs($user, ['week_start' => '2026-09-28'])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'There is no weekly review open for that week.');
    reviewCompleteAs(User::factory()->create())->assertStatus(422);
    expect(PlanWeek::query()->count())->toBe(0);
});

it('REV-001 saves the review once: next week\'s days and the stamp, then the review is no longer open', function () {
    $user = (new ReviewSeed)->seed();

    $result = reviewCompleteAs($user)->assertOk()->json('data');

    expect($result['invalidates'])->toContain(
        ReadKey::for('review_show'),
        ReadKey::for('progress_show'),
        ReadKey::for('today_show'),
        ReadKey::for('today_show', ['date' => '2026-10-18']),
        ReadKey::for('week_show', ['date' => '2026-10-12']),
        ReadKey::for('plan_show'),
    )
        ->and($result['toast'])->toBe('Review saved')
        ->and($result['undo'])->toBeNull()
        ->and(PlanWeek::query()->where('user_id', $user->id)->whereDate('start_date', '2026-10-05')->sole()->reviewed_at)->not->toBeNull()
        ->and(DayTarget::query()->where('user_id', $user->id)->whereBetween('date', ['2026-10-12', '2026-10-18'])->count())->toBe(7);

    reviewCompleteAs($user)->assertStatus(422)->assertJsonPath('error.message', 'There is no weekly review open for that week.');
    $this->actingAs($user)->postJson('/capabilities/review_show')->assertOk()->assertJsonPath('data.state', 'unavailable');
});

it('REV-011 a moved deload says the mesocycle\'s screens changed', function () {
    $user = (new ReviewSeed)->seed();
    $this->actingAs($user)->postJson('/capabilities/review_answer', ['week_start' => '2026-10-05', 'option' => 'drop_fatigue'], ['Idempotency-Key' => (string) Str::ulid()])->assertOk();
    $mesocycle = Mesocycle::query()->where('user_id', $user->id)->sole();

    $result = reviewCompleteAs($user)->assertOk()->json('data');

    expect($mesocycle->fresh()->weeks)->toBe(5)
        ->and($result['invalidates'])->toContain(ReadKey::for('train_show'), ReadKey::for('mesocycle_show', ['mesocycle_id' => $mesocycle->id]));
});

it('NUT-022 answers 422 when the stored answers do not build, and writes nothing', function () {
    $user = (new ReviewSeed)->seed();
    $this->actingAs($user)->postJson('/capabilities/review_answer', ['week_start' => '2026-10-05', 'option' => 'choose_own', 'own_kcal' => 1000], ['Idempotency-Key' => (string) Str::ulid()])->assertOk();

    reviewCompleteAs($user)
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'That is too few calories to fit your protein, fat and carbs. Choose a higher average.');
    expect(PlanWeek::query()->where('user_id', $user->id)->count())->toBe(0);
});
