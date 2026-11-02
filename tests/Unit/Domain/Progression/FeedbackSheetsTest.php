<?php

use App\Domain\Model\MuscleGroup;
use App\Domain\Progression\FeedbackBlock;
use App\Domain\Progression\FeedbackSheet;
use App\Domain\Progression\FeedbackSheets;
use App\Domain\Progression\WorkoutStatus;

// Which Feedback sheets a session asks, and when each falls due (rp-clone WorkoutModel+Feedback). workout_show
// sends them so the session can be answered offline (OFF-020); workout_feedback_answer reads the same rules.

/** @return list<FeedbackBlock> bench, fly (both chest) and row, with fly done */
function fbBlocks(bool $benchDone = false, bool $rowDone = false, int $rowSets = 3): array
{
    return [
        new FeedbackBlock('bench', 12, 'Bench Press', MuscleGroup::Chest, 3, $benchDone),
        new FeedbackBlock('row', 25, 'Barbell Row', MuscleGroup::Back, $rowSets, $rowDone),
        new FeedbackBlock('fly', 14, 'Cable Fly', MuscleGroup::Chest, 2, true),
        new FeedbackBlock('bench-again', 12, 'Bench Press', MuscleGroup::Chest, 0, true),
    ];
}

it('FB-001 OFF-020 one sheet per muscle, due once its last exercise with sets is done, in the order those exercises come', function () {
    $sheets = FeedbackSheets::sheets(fbBlocks(), isDeload: false, status: WorkoutStatus::InProgress);

    expect(array_map(fn (FeedbackSheet $s) => $s->muscle, $sheets))->toBe([MuscleGroup::Back, MuscleGroup::Chest])
        ->and(array_map(fn (FeedbackSheet $s) => $s->dueAfter, $sheets))->toBe(['row', 'fly'])
        ->and(array_map(fn (FeedbackSheet $s) => $s->isDue, $sheets))->toBe([false, true]);
});

it('FB-002 joint pain is asked once for each exercise of the muscle that has sets', function () {
    $chest = FeedbackSheets::sheets(fbBlocks(), false, WorkoutStatus::InProgress)[1];

    expect($chest->exercises)->toBe([12 => 'Bench Press', 14 => 'Cable Fly']);
});

it('FB-001 an exercise left with no sets asks nothing', function () {
    $sheets = FeedbackSheets::sheets(fbBlocks(rowSets: 0), false, WorkoutStatus::InProgress);

    expect(array_map(fn (FeedbackSheet $s) => $s->muscle, $sheets))->toBe([MuscleGroup::Chest]);
});

it('FB-008 no sheet in a deload week, and none for a skipped session', function () {
    expect(FeedbackSheets::sheets(fbBlocks(), true, WorkoutStatus::InProgress))->toBe([])
        ->and(FeedbackSheets::sheets(fbBlocks(), false, WorkoutStatus::Skipped))->toBe([]);
});

it('FB-007 soreness is asked only when the muscle trained here within the last 14 days', function () {
    $start = new DateTimeImmutable('2026-09-28 17:30:00');

    expect(FeedbackSheets::asksSoreness($start, null))->toBeFalse()
        ->and(FeedbackSheets::asksSoreness($start, new DateTimeImmutable('2026-09-14 17:30:00')))->toBeTrue()
        ->and(FeedbackSheets::asksSoreness($start, new DateTimeImmutable('2026-09-14 17:29:00')))->toBeFalse();
});

it('WO-040 a sheet is answered with pump, volume, soreness when asked, and every joint question', function () {
    $chest = FeedbackSheets::sheets(fbBlocks(), false, WorkoutStatus::InProgress)[1];

    expect(FeedbackSheets::isAnswered($chest, true, 'stillSore', 'low', 'justRight', [12, 14]))->toBeTrue()
        ->and(FeedbackSheets::isAnswered($chest, false, null, 'low', 'justRight', [12, 14]))->toBeTrue()
        ->and(FeedbackSheets::isAnswered($chest, true, null, 'low', 'justRight', [12, 14]))->toBeFalse()
        ->and(FeedbackSheets::isAnswered($chest, false, null, null, 'justRight', [12, 14]))->toBeFalse()
        ->and(FeedbackSheets::isAnswered($chest, false, null, 'low', 'justRight', [12]))->toBeFalse();
});
