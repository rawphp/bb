<?php

// The same tests as rp-clone's FatigueTests.swift (pin 2aa1edeb), one for one.

use App\Domain\Model\FeedbackAnswers;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Model\PlanWeek;
use App\Domain\Model\Soreness;
use App\Domain\Model\VolumeFeedback;
use App\Domain\Review\DeloadOffer;
use App\Domain\Review\DropFatigueReason;
use App\Domain\Review\Fatigue;
use App\Domain\Review\FatigueAdvice;
use App\Domain\Review\FatigueWeek;

function fatigueStart(): DateTimeImmutable
{
    return new DateTimeImmutable('@1800000000');
}

/** @return list<MuscleGroup> */
function fatigueTwoMuscles(): array
{
    return [MuscleGroup::Chest, MuscleGroup::Back];
}

/**
 * @param  list<MuscleGroup>  $under
 * @param  list<FeedbackAnswers>  $sheets
 */
function fatigueWeek(
    array $under = [],
    array $sheets = [],
    int $skipped = 0,
    int $logged = 7,
    bool $deload = false,
    bool $dietBreak = false,
    bool $away = false,
): FatigueWeek {
    return new FatigueWeek(
        week: new PlanWeek(start: fatigueStart(), isDeload: $deload, isDietBreak: $dietBreak, isAway: $away),
        underperformingMuscles: $under, feedback: $sheets, skippedSessions: $skipped, loggedDays: $logged,
    );
}

function fatigueSheet(?Soreness $soreness = null, ?VolumeFeedback $volume = null): FeedbackAnswers
{
    return new FeedbackAnswers(soreness: $soreness, volume: $volume);
}

/**
 * `$total` answers: `$bad` of them are "still sore", the rest are fine.
 *
 * @return list<FeedbackAnswers>
 */
function fatigueAnswers(int $bad, int $total): array
{
    return array_map(
        fn (int $i) => $i < $bad ? fatigueSheet(Soreness::StillSore) : fatigueSheet(Soreness::HealedJustOnTime),
        $total > 0 ? range(0, $total - 1) : [],
    );
}

function fatigueNormalWeek(): PlanWeek
{
    return new PlanWeek(start: fatigueStart());
}

function fatigueHighTraining(): FatigueWeek
{
    return fatigueWeek(under: fatigueTwoMuscles());
}

function fatigueOkWeek(): FatigueWeek
{
    return fatigueWeek();
}

function fatigueBreakWeek(): FatigueWeek
{
    return fatigueWeek(deload: true, dietBreak: true);
}

/** @return list<FatigueWeek> */
function fatigueRepeat(FatigueWeek $week, int $count): array
{
    return array_fill(0, $count, $week);
}

// REV-040

it('REV-040 training fatigue counts flags, answers and skipped sessions', function () {
    $result = Fatigue::training(fatigueWeek(
        under: [MuscleGroup::Chest],
        sheets: [
            fatigueSheet(Soreness::StillSore, VolumeFeedback::JustRight),
            fatigueSheet(Soreness::NeverGotSore, VolumeFeedback::JustRight),
            fatigueSheet(Soreness::HealedAWhileAgo, VolumeFeedback::TooMuch),
            fatigueSheet(null, VolumeFeedback::JustRight),
            fatigueSheet(),
        ],
        skipped: 2,
    ));
    expect($result->underperformingMuscles)->toBe(1)
        ->and($result->stillSoreOrTooMuchAnswers)->toBe(2)
        ->and($result->soreAndVolumeAnswers)->toBe(7)
        ->and($result->skippedSessions)->toBe(2)
        ->and($result->isHigh())->toBeFalse();
});

it('REV-040 skipped sessions alone are not high', function () {
    expect(Fatigue::training(fatigueWeek(skipped: 5))->isHigh())->toBeFalse();
});

it('REV-040 diet fatigue counts weeks since break, stall and adherence', function () {
    $weeks = [fatigueWeek(logged: 7), fatigueWeek(logged: 7), fatigueWeek(logged: 7), fatigueWeek(logged: 6), fatigueWeek(logged: 4)];
    $result = Fatigue::diet(PhaseType::FatLoss, $weeks, false);
    expect($result->deficitWeeksSinceBreak)->toBe(5)
        ->and($result->isStalled)->toBeFalse()
        ->and($result->loggedDaysDrop)->toEqualWithDelta(20.0 / 3.0 - 4.0, 0.0001)
        ->and($result->isHigh())->toBeTrue()
        ->and(Fatigue::diet(PhaseType::FatLoss, [fatigueOkWeek()], true)->isHigh())->toBeTrue();
});

it('REV-040 weeks since break counts back from newest and stops at a break or deload', function () {
    $weeks = [fatigueOkWeek(), fatigueWeek(dietBreak: true), fatigueOkWeek(), fatigueOkWeek()];
    expect(Fatigue::diet(PhaseType::FatLoss, $weeks, false)->deficitWeeksSinceBreak)->toBe(2);
    $deloaded = [fatigueOkWeek(), fatigueWeek(deload: true), fatigueOkWeek()];
    expect(Fatigue::diet(PhaseType::FatLoss, $deloaded, false)->deficitWeeksSinceBreak)->toBe(1);
});

it('REV-040 no deficit weeks outside fat loss', function () {
    $weeks = fatigueRepeat(fatigueOkWeek(), 8);
    expect(Fatigue::diet(PhaseType::Maintenance, $weeks, false)->deficitWeeksSinceBreak)->toBe(0)
        ->and(Fatigue::diet(PhaseType::MuscleGain, $weeks, false)->deficitWeeksSinceBreak)->toBe(0);
});

it('REV-040 logged days drop is nil without enough earlier weeks', function () {
    $weeks = [fatigueWeek(logged: 7), fatigueWeek(logged: 7), fatigueWeek(logged: 1)];
    expect(Fatigue::diet(PhaseType::FatLoss, $weeks, false)->loggedDaysDrop)->toBeNull();
});

it('REV-040 logged days drop uses the three weeks before the newest', function () {
    $weeks = [fatigueWeek(logged: 1), fatigueWeek(logged: 7), fatigueWeek(logged: 6), fatigueWeek(logged: 5), fatigueWeek(logged: 3)];
    $drop = Fatigue::diet(PhaseType::FatLoss, $weeks, false)->loggedDaysDrop;
    expect($drop)->toEqualWithDelta(3.0, 0.0001);
});

// REV-041

it('REV-041 high training fatigue in fat loss recommends drop fatigue with the text', function () {
    $advice = Fatigue::advice(PhaseType::FatLoss, [fatigueHighTraining()], fatigueNormalWeek());
    expect($advice)->toEqual(FatigueAdvice::dropFatigue(DropFatigueReason::TrainingFatigue))
        ->and($advice?->message())->toBe('Your lifts are dropping. It might be the diet, not the training.');
});

it('REV-041 mini cut counts as fat loss', function () {
    expect(Fatigue::advice(PhaseType::MiniCut, [fatigueHighTraining()], fatigueNormalWeek()))
        ->toEqual(FatigueAdvice::dropFatigue(DropFatigueReason::TrainingFatigue));
});

it('REV-041 no advice when training fatigue is not high', function () {
    expect(Fatigue::advice(PhaseType::FatLoss, [fatigueOkWeek()], fatigueNormalWeek()))->toBeNull();
});

it('REV-041 no advice when the week just finished was a break', function () {
    $weeks = [fatigueWeek(under: fatigueTwoMuscles(), dietBreak: true)];
    expect(Fatigue::advice(PhaseType::FatLoss, $weeks, fatigueNormalWeek()))->toBeNull()
        ->and(Fatigue::advice(PhaseType::FatLoss, [], fatigueNormalWeek()))->toBeNull();
});

it('REV-041 no advice when next week is already a diet break or deload', function () {
    $dietBreakNext = new PlanWeek(start: fatigueStart(), isDietBreak: true);
    $deloadNext = new PlanWeek(start: fatigueStart(), isDeload: true);
    expect(Fatigue::advice(PhaseType::FatLoss, [fatigueHighTraining()], $dietBreakNext))->toBeNull()
        ->and(Fatigue::advice(PhaseType::FatLoss, [fatigueHighTraining()], $deloadNext))->toBeNull();
});

it('REV-041 only the text for training fatigue exists', function () {
    expect(FatigueAdvice::dropFatigue(DropFatigueReason::WeeksSinceBreak)->message())->toBeNull()
        ->and(FatigueAdvice::endFatLoss()->message())->toBeNull();
});

// REV-042

it('REV-042 six deficit weeks suggest drop fatigue without other signs', function () {
    expect(Fatigue::advice(PhaseType::FatLoss, fatigueRepeat(fatigueOkWeek(), 6), fatigueNormalWeek()))
        ->toEqual(FatigueAdvice::dropFatigue(DropFatigueReason::WeeksSinceBreak));
});

it('REV-042 five deficit weeks are not enough', function () {
    expect(Fatigue::advice(PhaseType::FatLoss, fatigueRepeat(fatigueOkWeek(), 5), fatigueNormalWeek()))->toBeNull();
});

it('REV-042 a diet break resets the count', function () {
    $weeks = [...fatigueRepeat(fatigueOkWeek(), 4), fatigueWeek(dietBreak: true), ...fatigueRepeat(fatigueOkWeek(), 3)];
    expect(Fatigue::advice(PhaseType::FatLoss, $weeks, fatigueNormalWeek()))->toBeNull();
});

it('REV-042 training fatigue reason wins over weeks since break', function () {
    $weeks = [...fatigueRepeat(fatigueOkWeek(), 5), fatigueHighTraining()];
    expect(Fatigue::advice(PhaseType::FatLoss, $weeks, fatigueNormalWeek()))
        ->toEqual(FatigueAdvice::dropFatigue(DropFatigueReason::TrainingFatigue));
});

// REV-043

it('REV-043 high after a diet break and deload ends fat loss', function () {
    $weeks = [fatigueHighTraining(), fatigueBreakWeek(), fatigueHighTraining()];
    expect(Fatigue::advice(PhaseType::FatLoss, $weeks, fatigueNormalWeek()))->toEqual(FatigueAdvice::endFatLoss());
});

it('REV-043 a longer break run still counts', function () {
    $weeks = [fatigueHighTraining(), fatigueWeek(dietBreak: true), fatigueWeek(deload: true, dietBreak: true), fatigueHighTraining()];
    expect(Fatigue::advice(PhaseType::FatLoss, $weeks, fatigueNormalWeek()))->toEqual(FatigueAdvice::endFatLoss());
});

it('REV-043 a break without a deload falls back to drop fatigue', function () {
    $weeks = [fatigueHighTraining(), fatigueWeek(dietBreak: true), fatigueHighTraining()];
    expect(Fatigue::advice(PhaseType::FatLoss, $weeks, fatigueNormalWeek()))
        ->toEqual(FatigueAdvice::dropFatigue(DropFatigueReason::TrainingFatigue));
});

it('REV-043 fatigue that was not high before the break falls back to drop fatigue', function () {
    $weeks = [fatigueOkWeek(), fatigueBreakWeek(), fatigueHighTraining()];
    expect(Fatigue::advice(PhaseType::FatLoss, $weeks, fatigueNormalWeek()))
        ->toEqual(FatigueAdvice::dropFatigue(DropFatigueReason::TrainingFatigue));
});

it('REV-043 no week before the break run falls back to drop fatigue', function () {
    expect(Fatigue::advice(PhaseType::FatLoss, [fatigueBreakWeek(), fatigueHighTraining()], fatigueNormalWeek()))
        ->toEqual(FatigueAdvice::dropFatigue(DropFatigueReason::TrainingFatigue));
});

it('REV-043 a deload without a diet break counts as the break', function () {
    $weeks = [fatigueHighTraining(), fatigueWeek(deload: true), fatigueHighTraining()];
    expect(Fatigue::advice(PhaseType::FatLoss, $weeks, fatigueNormalWeek()))->toEqual(FatigueAdvice::endFatLoss());
});

it('REV-043 ends fat loss even when next week is already a break', function () {
    $weeks = [fatigueHighTraining(), fatigueBreakWeek(), fatigueHighTraining()];
    $dietBreakNext = new PlanWeek(start: fatigueStart(), isDietBreak: true);
    $deloadNext = new PlanWeek(start: fatigueStart(), isDeload: true);
    expect(Fatigue::advice(PhaseType::FatLoss, $weeks, $dietBreakNext))->toEqual(FatigueAdvice::endFatLoss())
        ->and(Fatigue::advice(PhaseType::FatLoss, $weeks, $deloadNext))->toEqual(FatigueAdvice::endFatLoss());
});

it('REV-043 away week after the deload is skipped', function () {
    $weeks = [fatigueHighTraining(), fatigueBreakWeek(), fatigueWeek(away: true), fatigueHighTraining()];
    expect(Fatigue::advice(PhaseType::FatLoss, $weeks, fatigueNormalWeek()))->toEqual(FatigueAdvice::endFatLoss());
});

it('REV-043 away week before the break is skipped', function () {
    $weeks = [fatigueHighTraining(), fatigueWeek(away: true), fatigueBreakWeek(), fatigueHighTraining()];
    expect(Fatigue::advice(PhaseType::FatLoss, $weeks, fatigueNormalWeek()))->toEqual(FatigueAdvice::endFatLoss());
});

it('REV-043 away week inside the break run is skipped', function () {
    $weeks = [fatigueHighTraining(), fatigueWeek(dietBreak: true), fatigueWeek(away: true), fatigueWeek(deload: true), fatigueHighTraining()];
    expect(Fatigue::advice(PhaseType::FatLoss, $weeks, fatigueNormalWeek()))->toEqual(FatigueAdvice::endFatLoss());
});

it('REV-043 fatigue that eased after the break does not end fat loss', function () {
    expect(Fatigue::advice(PhaseType::FatLoss, [fatigueHighTraining(), fatigueBreakWeek(), fatigueOkWeek()], fatigueNormalWeek()))->toBeNull();
});

// REV-044

it('REV-044 muscle gain high fatigue never changes the diet', function () {
    expect(Fatigue::advice(PhaseType::MuscleGain, [fatigueHighTraining()], fatigueNormalWeek()))->toBeNull();
    $long = fatigueRepeat(fatigueHighTraining(), 8);
    expect(Fatigue::advice(PhaseType::MuscleGain, $long, fatigueNormalWeek()))->toBeNull()
        ->and(Fatigue::advice(PhaseType::Maintenance, $long, fatigueNormalWeek()))->toBeNull();
});

// SCH-061

it('SCH-061 away week contributes no fatigue', function () {
    $away = fatigueWeek(under: fatigueTwoMuscles(), sheets: fatigueAnswers(6, 6), skipped: 4, away: true);
    $result = Fatigue::training($away);
    expect($result->underperformingMuscles)->toBe(0)
        ->and($result->stillSoreOrTooMuchAnswers)->toBe(0)
        ->and($result->soreAndVolumeAnswers)->toBe(0)
        ->and($result->skippedSessions)->toBe(0)
        ->and($result->isHigh())->toBeFalse();
});

it('SCH-061 no fat loss advice from an away week', function () {
    $away = fatigueWeek(under: fatigueTwoMuscles(), sheets: fatigueAnswers(6, 6), skipped: 4, away: true);
    expect(Fatigue::advice(PhaseType::FatLoss, [$away], fatigueNormalWeek()))->toBeNull();
});

// TBL-051

it('TBL-051 exactly a third still sore or too much is not high', function () {
    expect(Fatigue::training(fatigueWeek(sheets: fatigueAnswers(2, 6)))->isHigh())->toBeFalse();
});

it('TBL-051 just above a third is high', function () {
    expect(Fatigue::training(fatigueWeek(sheets: fatigueAnswers(3, 8)))->isHigh())->toBeTrue();
});

it('TBL-051 soreness and volume answers each count', function () {
    $sheets = [fatigueSheet(Soreness::StillSore, VolumeFeedback::TooMuch), fatigueSheet(Soreness::HealedAWhileAgo, VolumeFeedback::JustRight)];
    $result = Fatigue::training(fatigueWeek(sheets: $sheets));
    expect($result->stillSoreOrTooMuchAnswers)->toBe(2)
        ->and($result->soreAndVolumeAnswers)->toBe(4)
        ->and($result->isHigh())->toBeTrue();
});

it('TBL-051 no answers is not high', function () {
    expect(Fatigue::training(fatigueWeek())->isHigh())->toBeFalse();
});

it('TBL-051 one underperforming muscle is not high, two is', function () {
    expect(Fatigue::training(fatigueWeek(under: [MuscleGroup::Chest]))->isHigh())->toBeFalse()
        ->and(Fatigue::training(fatigueWeek(under: fatigueTwoMuscles()))->isHigh())->toBeTrue();
});

it('TBL-051 five deficit weeks are not high, six are', function () {
    expect(Fatigue::diet(PhaseType::FatLoss, fatigueRepeat(fatigueOkWeek(), 5), false)->isHigh())->toBeFalse()
        ->and(Fatigue::diet(PhaseType::FatLoss, fatigueRepeat(fatigueOkWeek(), 6), false)->isHigh())->toBeTrue();
});

it('TBL-051 logged days drop of 1.67 is not high, 2 is', function () {
    $small = [fatigueWeek(logged: 7), fatigueWeek(logged: 7), fatigueWeek(logged: 6), fatigueWeek(logged: 5)];
    $big = [fatigueWeek(logged: 7), fatigueWeek(logged: 7), fatigueWeek(logged: 7), fatigueWeek(logged: 5)];
    expect(Fatigue::diet(PhaseType::FatLoss, $small, false)->isHigh())->toBeFalse()
        ->and(Fatigue::diet(PhaseType::FatLoss, $big, false)->isHigh())->toBeTrue();
});

it('TBL-051 a stall alone makes diet fatigue high', function () {
    expect(Fatigue::diet(PhaseType::FatLoss, [fatigueOkWeek()], true)->isHigh())->toBeTrue();
});

// REV-011

it('REV-011 deload one or two weeks away offers only deload now', function () {
    expect(Fatigue::deloadOffer(6, 5))->toEqual(DeloadOffer::deloadNow())
        ->and(Fatigue::deloadOffer(6, 4))->toEqual(DeloadOffer::deloadNow());
});

it('REV-011 otherwise asks with yes as default from three weeks done', function () {
    expect(Fatigue::deloadOffer(6, 3))->toEqual(DeloadOffer::askDeloadToo(true))
        ->and(Fatigue::deloadOffer(8, 4))->toEqual(DeloadOffer::askDeloadToo(true));
});

it('REV-011 fewer than three weeks done defaults to no', function () {
    expect(Fatigue::deloadOffer(6, 2))->toEqual(DeloadOffer::askDeloadToo(false))
        ->and(Fatigue::deloadOffer(8, 1))->toEqual(DeloadOffer::askDeloadToo(false));
});
