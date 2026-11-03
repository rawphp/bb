<?php

use App\Actions\Review\AnswerReview;
use App\Actions\Review\CompleteReview;
use App\Actions\Review\OpenReview;
use App\Capabilities\Reads\Review\ReviewPreviewPayload;
use App\Capabilities\Review\Data\ReviewAnswerInput;
use App\Models\ReviewAnswer;
use App\Models\User;
use App\Support\Review\ReviewAnalysis;
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

    return ReviewPreviewPayload::build($analysis, OpenReview::answers($user, $analysis))->toArray();
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

    $answers = ReviewAnswer::forWeek($user, '2026-10-05')->answers();
    expect(ReviewAnswer::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($answers->option)->toBe('repeat_this_week')
        ->and($answers->ownKcal)->toBeNull()
        ->and(ReviewAnswer::forWeek(rqaUser(), '2026-10-05'))->toBeNull();
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

it('NUT-024 REV-004 a refused move keeps the stored moves review_show still shows: those before the first that no longer builds', function () {
    $kept = ['from_index' => 3, 'to_index' => 4, 'kcal' => 100];
    $user = rqaUser();
    rqaAnswer($user, ['moves' => [$kept, ['from_index' => 0, 'to_index' => 5, 'kcal' => 100]]]);

    Carbon::setTestNow('2026-10-14 10:00:00');
    expect(rqaPreview($user)['moves'])->toBe([$kept]);
    // The screen sends what it shows plus a move that breaks the 80% floor on day 3.
    $row = rqaAnswer($user, ['moves' => [$kept, ['from_index' => 3, 'to_index' => 4, 'kcal' => 500], ['from_index' => 3, 'to_index' => 4, 'kcal' => 500]]]);

    expect($row->fresh()->moves)->toEqual([$kept])
        ->and($row->fresh()->refusal)->toBe(RQA_REFUSED_MOVE)
        ->and(rqaPreview($user)['moves'])->toBe([$kept]);
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

it('REV-010 Choose my own with no amount is stored as sent, a draft that applies nothing yet', function () {
    $user = rqaUser();

    $row = rqaAnswer($user, ['option' => 'choose_own', 'own_kcal' => null, 'update_step_plan' => true]);

    expect($row->fresh()->only(['option', 'own_kcal', 'update_step_plan', 'refusal']))->toBe([
        'option' => 'choose_own', 'own_kcal' => null, 'update_step_plan' => true, 'refusal' => null,
    ])
        ->and($row->fresh()->needsAmount())->toBeTrue()
        ->and(rqaAnswer(rqaUser(), ['option' => 'choose_own', 'own_kcal' => 2300])->needsAmount())->toBeFalse();
});

it('REV-010 an answer with only an amount sets it on the stored Choose my own, keeping its other answers and clearing its moves', function () {
    $user = rqaUser();
    rqaAnswer($user, ['option' => 'choose_own', 'update_step_plan' => true, 'moves' => [['from_index' => 0, 'to_index' => 5, 'kcal' => 100]]]);

    $row = rqaAnswer($user, ['option' => null, 'own_kcal' => 2300]);

    expect($row->fresh()->only(['option', 'own_kcal', 'update_step_plan', 'refusal']))->toBe([
        'option' => 'choose_own', 'own_kcal' => 2300, 'update_step_plan' => true, 'refusal' => null,
    ])
        ->and($row->fresh()->moves)->toBe([], 'a new amount changes the week the moves were made on')
        ->and(ReviewAnswer::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('REV-010 an answer with no option is refused unless it sends an amount for a stored Choose my own', function () {
    rqaRefused(rqaUser(), ['option' => null, 'own_kcal' => 2300], AnswerReview::AMOUNT_ONLY);

    $repeat = rqaUser();
    rqaAnswer($repeat, ['option' => 'repeat_this_week']);
    rqaRefused($repeat, ['option' => null, 'own_kcal' => 2300], AnswerReview::AMOUNT_ONLY);

    $own = rqaUser();
    rqaAnswer($own, ['option' => 'choose_own']);
    rqaRefused($own, ['option' => null], AnswerReview::AMOUNT_ONLY);
});

it('REV-010 a draft stays a draft when another answer echoes the amount review_show filled in, and a typed amount ends it', function () {
    $user = rqaUser();
    rqaAnswer($user, ['option' => 'choose_own']);
    $shown = ReviewShowWorld::shownOwnKcal($user);

    $echoed = rqaAnswer($user, ['option' => 'choose_own', 'own_kcal' => $shown, 'update_step_plan' => true, 'moves' => [['from_index' => 0, 'to_index' => 5, 'kcal' => 100]]]);
    expect($shown)->toBeInt()
        ->and($echoed->fresh()->needsAmount())->toBeTrue('the screen sent back its own pre-fill, not an amount the user typed')
        ->and($echoed->fresh()->update_step_plan)->toBeTrue()
        ->and($echoed->fresh()->moves)->toEqual([['from_index' => 0, 'to_index' => 5, 'kcal' => 100]]);

    expect(rqaAnswer($user, ['option' => 'choose_own', 'own_kcal' => $shown - 100])->fresh()->own_kcal)->toBe($shown - 100);
});

it('REV-010 the suggested amount sent with Choose my own counts as typed when it is the answer\'s only change', function () {
    $user = rqaUser();
    rqaAnswer($user, ['option' => 'choose_own', 'update_step_plan' => true]);
    $shown = ReviewShowWorld::shownOwnKcal($user);

    // The screen's own amount answer: the shown option, the typed amount, the other answers as shown, no moves.
    $row = rqaAnswer($user, ['option' => 'choose_own', 'own_kcal' => $shown, 'update_step_plan' => true, 'moves' => []]);

    expect($row->fresh()->own_kcal)->toBe($shown)
        ->and($row->fresh()->needsAmount())->toBeFalse();
});

it('REV-011 a draft stays a draft when the deload answer echoes the pre-filled amount', function () {
    $user = rqaUser(fn (ReviewSeed $seed) => $seed->mesocycleWeeks = 8);
    rqaAnswer($user, ['option' => 'choose_own']);
    $shown = ReviewShowWorld::shownOwnKcal($user);

    expect(rqaAnswer($user, ['option' => 'choose_own', 'own_kcal' => $shown, 'deload_too' => false])->fresh()->needsAmount())->toBeTrue();
});

it('REV-050 a draft stays a draft when the step offer answer echoes the pre-filled amount with the stored moves', function () {
    $user = rqaUser();
    $moves = [['from_index' => 0, 'to_index' => 5, 'kcal' => 100]];
    rqaAnswer($user, ['option' => 'choose_own', 'moves' => $moves]);
    $shown = ReviewShowWorld::shownOwnKcal($user);

    $row = rqaAnswer($user, ['option' => 'choose_own', 'own_kcal' => $shown, 'update_step_plan' => true, 'moves' => $moves]);

    expect($row->fresh()->needsAmount())->toBeTrue()
        ->and($row->fresh()->update_step_plan)->toBeTrue();
    expect(fn () => app(CompleteReview::class)($user, '2026-10-05'))->toThrow(DomainException::class, CompleteReview::NEEDS_AMOUNT);
});

it('REV-010 the screen\'s own amount answer for a draft with moves counts as typed, even at the pre-filled amount', function () {
    $user = rqaUser();
    rqaAnswer($user, ['option' => 'choose_own', 'moves' => [['from_index' => 0, 'to_index' => 5, 'kcal' => 100]]]);
    $shown = ReviewShowWorld::shownOwnKcal($user);

    // A new amount clears the moves, so the screen sends none.
    $row = rqaAnswer($user, ['option' => 'choose_own', 'own_kcal' => $shown, 'moves' => []]);

    expect($row->fresh()->own_kcal)->toBe($shown)
        ->and($row->fresh()->needsAmount())->toBeFalse();
});

it('REV-010 an amount sent alone for a draft is taken as typed, even when it is the amount review_show filled in', function () {
    $user = rqaUser();
    rqaAnswer($user, ['option' => 'choose_own']);
    $shown = ReviewShowWorld::shownOwnKcal($user);

    $row = rqaAnswer($user, ['option' => null, 'own_kcal' => $shown]);

    expect($row->fresh()->own_kcal)->toBe($shown)
        ->and($row->fresh()->needsAmount())->toBeFalse();
});

it('REV-010 refuses an own amount that is not a positive whole number, and writes nothing', function () {
    rqaRefused(rqaUser(), ['option' => 'choose_own', 'own_kcal' => 0], AnswerReview::NO_AMOUNT);

    $draft = rqaUser();
    rqaAnswer($draft, ['option' => 'choose_own']);
    rqaRefused($draft, ['option' => null, 'own_kcal' => 0], AnswerReview::NO_AMOUNT);
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
