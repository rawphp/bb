<?php

use App\Actions\Review\AnswerReview;
use App\Actions\Review\CompleteReview;
use App\Capabilities\Reads\Review\ReviewPreviewPayload;
use App\Capabilities\Review\Data\ReviewAnswerInput;
use App\Models\ReviewAnswer;
use App\Models\User;
use App\Support\Review\ReviewAnalysis;
use App\Support\Review\ReviewAnswers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\ReviewSeed;
use Tests\Support\ReviewShowWorld;

// review_answer's rules (REV-004, REV-010, REV-012, REV-031, NUT-022): what is validated, and what is stored. The HTTP
// seam is tests/Feature/Capabilities/Progress/ReviewAnswerTest.php.

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow(ReviewSeed::NOW));
afterEach(fn () => Carbon::setTestNow());

const RQA_REFUSED_MOVE = 'A day stays between 80% and 125% of the week\'s average, and a day already past can\'t change.';

function rqaUser(?Closure $change = null): User
{
    $seed = new ReviewSeed;
    if ($change !== null) {
        $change($seed);
    }

    return $seed->seed();
}

/** @param  array<string, mixed>  $input */
function rqaAnswer(User $user, array $input): ReviewAnswer
{
    return app(AnswerReview::class)($user, ReviewAnswerInput::from(['week_start' => '2026-10-05', 'option' => 'recommendation', ...$input]));
}

/** @param  array<string, mixed>  $input */
function rqaRefused(User $user, array $input, string $message): void
{
    $before = ReviewAnswer::query()->get()->toArray();

    expect(fn () => rqaAnswer($user, $input))->toThrow(DomainException::class, $message)
        ->and(ReviewAnswer::query()->get()->toArray())->toBe($before);
}

/** @return array<string, mixed> the preview review_show gives the stored answers */
function rqaPreview(User $user): array
{
    $analysis = ReviewAnalysis::for($user, Carbon::now()->format('Y-m-d')) ?? throw new RuntimeException('No review is open.');

    return ReviewPreviewPayload::build($analysis, ReviewAnswer::answersFor($user, '2026-10-05'))->toArray();
}

/** @param  array<string, mixed>  $preview */
function rqaKcal(array $preview): array
{
    return array_map(fn (array $day) => (int) $day['kcal_label'], $preview['days']);
}

it('REV-004 REV-031 stores the answer for the open week as sent, owned by the user who sent it', function () {
    $user = rqaUser();
    $other = rqaUser();

    $row = rqaAnswer($user, [
        'option' => 'choose_own', 'own_kcal' => 2300, 'deload_too' => true, 'update_step_plan' => true,
        'moves' => [['from_index' => 0, 'to_index' => 6, 'kcal' => 100]],
    ]);

    // A json column keeps its own key order, so the rows compare by value.
    expect($row->fresh()->only(['user_id', 'option', 'own_kcal', 'deload_too', 'update_step_plan', 'moves', 'refusal']))->toEqual([
        'user_id' => $user->id, 'option' => 'choose_own', 'own_kcal' => 2300, 'deload_too' => true, 'update_step_plan' => true,
        'moves' => [['from_index' => 0, 'to_index' => 6, 'kcal' => 100]], 'refusal' => null,
    ])
        ->and($row->fresh()->week_start->format('Y-m-d'))->toBe('2026-10-05')
        ->and(ReviewAnswer::query()->where('user_id', $other->id)->exists())->toBeFalse();
});

it('REV-004 answering again replaces the week\'s answer, and the stored answer is what review_show selects', function () {
    $user = rqaUser();

    rqaAnswer($user, ['option' => 'choose_own', 'own_kcal' => 2300]);
    rqaAnswer($user, ['option' => 'repeat_this_week']);

    $answers = ReviewAnswer::answersFor($user, '2026-10-05');
    expect(ReviewAnswer::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($answers->option)->toBe('repeat_this_week')
        ->and($answers->ownKcal)->toBeNull()
        ->and(ReviewAnswer::answersFor(rqaUser(), '2026-10-05'))->toBe(ReviewAnswers::defaults());
});

it('REV-004 a move that builds changes the preview, and the week\'s total holds', function () {
    $user = rqaUser();
    $before = rqaKcal(rqaPreview($user));

    rqaAnswer($user, ['moves' => [['from_index' => 0, 'to_index' => 5, 'kcal' => 100]]]);
    $after = rqaPreview($user);

    expect(rqaKcal($after)[0])->toBe($before[0] - 100)
        ->and(rqaKcal($after)[5])->toBe($before[5] + 100)
        ->and(array_sum(rqaKcal($after)))->toBe(array_sum($before))
        ->and($after['error'])->toBeNull();
});

it('REV-004 NUT-022 a move that will not build is stored with its refusal, keeping the last moves that built', function () {
    $user = rqaUser();
    $before = rqaKcal(rqaPreview($user));
    rqaAnswer($user, ['moves' => [['from_index' => 0, 'to_index' => 1, 'kcal' => 100]]]);

    $row = rqaAnswer($user, ['option' => 'recommendation', 'update_step_plan' => true, 'moves' => [
        ['from_index' => 0, 'to_index' => 1, 'kcal' => 100],
        ['from_index' => 0, 'to_index' => 1, 'kcal' => 500],
        ['from_index' => 0, 'to_index' => 1, 'kcal' => 500],
    ]]);
    $preview = rqaPreview($user);

    expect($row->fresh()->moves)->toEqual([['from_index' => 0, 'to_index' => 1, 'kcal' => 100]])
        ->and($row->fresh()->refusal)->toBe(RQA_REFUSED_MOVE)
        ->and($row->fresh()->update_step_plan)->toBeTrue()
        ->and($preview['error'])->toBe(RQA_REFUSED_MOVE)
        ->and($preview['moves'])->toBe([['from_index' => 0, 'to_index' => 1, 'kcal' => 100]])
        ->and(rqaKcal($preview)[0])->toBe($before[0] - 100)
        ->and(rqaKcal($preview)[1])->toBe($before[1] + 100);
});

it('REV-004 a refused move with no moves before it keeps none, and the preview shows the days as they were', function () {
    $user = rqaUser();
    $before = rqaKcal(rqaPreview($user));

    $row = rqaAnswer($user, ['moves' => [['from_index' => 0, 'to_index' => 1, 'kcal' => 500], ['from_index' => 0, 'to_index' => 1, 'kcal' => 500]]]);
    $preview = rqaPreview($user);

    expect($row->fresh()->moves)->toBe([])
        ->and($row->fresh()->refusal)->toBe(RQA_REFUSED_MOVE)
        ->and($preview['error'])->toBe(RQA_REFUSED_MOVE)
        ->and(rqaKcal($preview))->toBe($before);
});

it('NUT-024 REV-004 moves kept from earlier only stay when they still build: a day that has passed since drops them', function () {
    $user = rqaUser();
    rqaAnswer($user, ['moves' => [['from_index' => 0, 'to_index' => 5, 'kcal' => 100]]]);

    Carbon::setTestNow('2026-10-14 10:00:00');
    $row = rqaAnswer($user, ['moves' => [['from_index' => 0, 'to_index' => 5, 'kcal' => 100], ['from_index' => 2, 'to_index' => 5, 'kcal' => 100]]]);

    expect($row->fresh()->moves)->toBe([])
        ->and($row->fresh()->refusal)->toBe(RQA_REFUSED_MOVE)
        ->and(rqaPreview($user)['days'])->toHaveCount(7);
});

it('REV-004 clearing the moves puts the days back and clears the refusal', function () {
    $user = rqaUser();
    $before = rqaKcal(rqaPreview($user));
    rqaAnswer($user, ['moves' => [['from_index' => 0, 'to_index' => 5, 'kcal' => 100]]]);
    rqaAnswer($user, ['moves' => [['from_index' => 0, 'to_index' => 5, 'kcal' => 900]]]);

    $row = rqaAnswer($user, ['option' => 'repeat_this_week', 'moves' => []]);

    expect($row->fresh()->refusal)->toBeNull()
        ->and(rqaKcal(rqaPreview($user)))->toBe($before);
});

it('NUT-022 an option that will not build is stored as sent, with its refusal, and leaves no days to save', function () {
    $user = rqaUser();

    $row = rqaAnswer($user, ['option' => 'choose_own', 'own_kcal' => 1000, 'moves' => [['from_index' => 0, 'to_index' => 1, 'kcal' => 100]]]);
    $preview = rqaPreview($user);

    expect($row->fresh()->only(['option', 'own_kcal', 'moves']))->toEqual([
        'option' => 'choose_own', 'own_kcal' => 1000, 'moves' => [['from_index' => 0, 'to_index' => 1, 'kcal' => 100]],
    ])
        ->and($row->fresh()->refusal)->toBe('That is too few calories to fit your protein, fat and carbs. Choose a higher average.')
        ->and($preview['days'])->toBe([])
        ->and($preview['error'])->toBe($row->fresh()->refusal);
});

it('REV-010 refuses Drop fatigue when the review does not offer it, and writes nothing', function () {
    $user = rqaUser(fn (ReviewSeed $seed) => ReviewShowWorld::muscleGain($seed));

    rqaRefused($user, ['option' => 'drop_fatigue'], 'That option is not one this review offers.');
});

it('REV-012 refuses an option outside a Fat loss deload\'s Repeat and Choose my own, and writes nothing', function () {
    $user = rqaUser(fn (ReviewSeed $seed) => $seed->mesocycleWeeks = 5);

    rqaRefused($user, ['option' => 'recommendation'], 'That option is not one this review offers.');
    rqaRefused($user, ['option' => 'drop_fatigue'], 'That option is not one this review offers.');
    expect(rqaAnswer($user, ['option' => 'repeat_this_week'])->exists)->toBeTrue();
});

it('REV-027 refuses a stall\'s options when progress has not stalled', function () {
    rqaRefused(rqaUser(), ['option' => 'add_steps'], 'That option is not one this review offers.');
});

it('REV-010 refuses Choose my own with no amount, and writes nothing', function () {
    rqaRefused(rqaUser(), ['option' => 'choose_own'], 'Enter the average calories a day you want.');
    rqaRefused(rqaUser(), ['option' => 'choose_own', 'own_kcal' => 0], 'Enter the average calories a day you want.');
});

it('REV-001 refuses a week that is not the review open now, a week already reviewed, and a user with no review', function () {
    $user = rqaUser();
    rqaRefused($user, ['week_start' => '2026-09-28'], 'There is no weekly review open for that week.');
    rqaRefused($user, ['week_start' => '2026-10-12'], 'There is no weekly review open for that week.');

    app(CompleteReview::class)($user, '2026-10-05');
    rqaRefused($user, [], 'There is no weekly review open for that week.');
    rqaRefused(User::factory()->create(), [], 'There is no weekly review open for that week.');
});

it('SCH-061 an away review lists no calorie option, so it takes moves under the unanswered option only', function () {
    $user = rqaUser(fn (ReviewSeed $seed) => $seed->planWeekRows = [
        ['start_date' => '2026-10-05', 'is_away' => true, 'away_training' => 'pause', 'away_calories' => 'maintenance'],
    ]);

    rqaRefused($user, ['option' => 'repeat_this_week'], 'That option is not one this review offers.');
    $row = rqaAnswer($user, ['moves' => [['from_index' => 0, 'to_index' => 5, 'kcal' => 100]]]);

    expect($row->fresh()->moves)->toEqual([['from_index' => 0, 'to_index' => 5, 'kcal' => 100]])
        ->and($row->fresh()->refusal)->toBeNull();
});
