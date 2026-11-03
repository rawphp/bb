<?php

use App\Capabilities\Reads\Review\ReviewPreviewPayload;
use App\Domain\WeekBuilder\CalorieMove;
use App\Support\Review\ReviewAnswers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\ReviewSeed;
use Tests\Support\ReviewShowWorld;

// Step 7 of review_show (rp-clone WeeklyReviewModel+Preview): next week's days for the answers so far.

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow(ReviewSeed::NOW));
afterEach(fn () => Carbon::setTestNow());

/** @return array<string, mixed> */
function rvPreview(?Closure $change = null, ?ReviewAnswers $answers = null, string $today = ReviewSeed::TODAY): array
{
    return ReviewPreviewPayload::build(ReviewShowWorld::analysis($change, $today), $answers ?? ReviewAnswers::defaults())->toArray();
}

/** @param  array<string, mixed>  $preview */
function rvDayKcal(array $preview): array
{
    return array_map(fn (array $day) => $day['kcal_label'] === null ? null : (int) $day['kcal_label'], $preview['days']);
}

function rvWith(string $option = 'recommendation', ?int $ownKcal = null, ?bool $deloadToo = null, array $moves = [], ?string $refusal = null): ReviewAnswers
{
    return new ReviewAnswers($option, $ownKcal, $deloadToo, false, $moves, $refusal);
}

it('REV-003 the preview lists next week\'s seven days with their type, session and calories', function () {
    $preview = rvPreview();

    expect($preview['days'])->toHaveCount(7)
        ->and($preview['days'][0])->toMatchArray([
            'index' => 0, 'date' => '2026-10-12', 'date_label' => 'Mon, 12 Oct', 'detail' => 'Moderate · Upper A',
            'can_move' => true, 'is_locked' => false,
        ])
        ->and($preview['days'][0]['kcal_label'])->toMatch('/^\d+ kcal$/')
        ->and($preview['days'][1])->toMatchArray(['date' => '2026-10-13', 'detail' => 'Rest'])
        ->and(array_sum(rvDayKcal($preview)))->toBeGreaterThan(7 * 2399)->toBeLessThan(7 * 2401)
        ->and($preview['moves'])->toBe([])
        ->and($preview['hint'])->toBe('Tap a day to move calories between days. The week\'s total stays the same.')
        ->and($preview['error'])->toBeNull()
        ->and($preview['move'])->toBe(['title' => 'Move calories', 'min_kcal' => 25, 'max_kcal' => 500, 'step_kcal' => 25]);
});

it('NUT-024 REV-004 a review saved late locks the days already past out of moves', function () {
    $preview = rvPreview(today: '2026-10-15');

    expect(array_column($preview['days'], 'is_locked'))->toBe([true, true, true, false, false, false, false])
        ->and(array_column($preview['days'], 'can_move'))->toBe([false, false, false, true, true, true, true]);
});

it('REV-004 a calorie move shifts calories between two days and holds the week\'s total, echoing the moves', function () {
    $plain = rvPreview();
    $moved = rvPreview(answers: rvWith(moves: [new CalorieMove(0, 1, 100)]));

    $before = rvDayKcal($plain);
    $after = rvDayKcal($moved);
    expect($after[0])->toBe($before[0] - 100)
        ->and($after[1])->toBe($before[1] + 100)
        ->and(array_sum($after))->toBe(array_sum($before))
        ->and($moved['moves'])->toBe([['from_index' => 0, 'to_index' => 1, 'kcal' => 100]])
        ->and($moved['error'])->toBeNull();
});

it('NUT-022 REV-004 a move the rules refuse leaves no days and says why', function () {
    $tooBig = rvPreview(answers: rvWith(moves: [new CalorieMove(0, 1, 500), new CalorieMove(0, 1, 500)]));
    $past = rvPreview(answers: rvWith(moves: [new CalorieMove(0, 3, 100)]), today: '2026-10-15');

    expect($tooBig['days'])->toBe([])
        ->and($tooBig['error'])->toBe('A day stays between 80% and 125% of the week\'s average, and a day already past can\'t change.')
        ->and($past['days'])->toBe([])
        ->and($past['error'])->toBe($tooBig['error']);
});

it('REV-004 the answered refusal is the preview\'s error while the days still build from the moves', function () {
    $preview = rvPreview(answers: rvWith(moves: [new CalorieMove(0, 1, 100)], refusal: 'That move is not allowed.'));

    expect($preview['error'])->toBe('That move is not allowed.')
        ->and($preview['days'])->toHaveCount(7);
});

it('REV-010 Choose my own builds next week at the amount answered', function () {
    $preview = rvPreview(answers: rvWith('choose_own', 2200));

    expect(array_sum(rvDayKcal($preview)))->toBeGreaterThan(7 * 2199)->toBeLessThan(7 * 2201);
});

it('SAFE-012 REV-010 an amount safety refuses leaves no days and says why', function () {
    $preview = rvPreview(answers: rvWith('choose_own', 900));

    expect($preview['days'])->toBe([])
        ->and($preview['error'])->toBe('That is too few calories to fit your protein, fat and carbs. Choose a higher average.');
});

it('REV-010 Choose my own with no amount leaves no days and no error', function () {
    $preview = rvPreview(answers: rvWith('choose_own', 0));

    expect($preview['days'])->toBe([])->and($preview['error'])->toBeNull();
});

it('REV-011 Drop fatigue builds next week at maintenance, above the cut', function () {
    $preview = rvPreview(answers: rvWith('drop_fatigue', deloadToo: true));

    expect(array_sum(rvDayKcal($preview)) / 7)->toBeGreaterThan(2400);
});

it('REV-002 Repeat this week builds the week that ended again', function () {
    $preview = rvPreview(answers: rvWith('repeat_this_week'));

    expect(array_sum(rvDayKcal($preview)))->toBeGreaterThan(7 * 2399)->toBeLessThan(7 * 2401);
});

it('SCH-061 an away review previews next week at the repeated calories', function () {
    $preview = rvPreview(fn (ReviewSeed $seed) => $seed->planWeekRows = [
        ['start_date' => '2026-10-05', 'is_away' => true, 'away_training' => 'pause', 'away_calories' => 'maintenance'],
    ]);

    expect($preview['days'])->toHaveCount(7)->and($preview['error'])->toBeNull();
});
