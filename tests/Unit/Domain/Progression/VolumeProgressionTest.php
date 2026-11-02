<?php

use App\Domain\Defaults\TBL012TrainingDefaults\Column;
use App\Domain\Model\ExerciseSlot;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\FeedbackAnswers;
use App\Domain\Model\JointPain;
use App\Domain\Model\Mesocycle;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Priority;
use App\Domain\Model\Pump;
use App\Domain\Model\Session;
use App\Domain\Model\Soreness;
use App\Domain\Model\VolumeFeedback;
use App\Domain\Model\Workout;
use App\Domain\Progression\NextOccurrence;
use App\Domain\Progression\PhaseLandmarks;
use App\Domain\Progression\VolumeProgression;

// Builders. Ids are unique per call, as Swift's UUIDs are.

function vpId(string $prefix): string
{
    static $next = 0;

    return $prefix.'-'.++$next;
}

function vpSlot(int $sets, MuscleGroup $muscle = MuscleGroup::Chest, string $name = 'Press'): ExerciseSlot
{
    return new ExerciseSlot(vpId('slot'), $name, $muscle, $sets);
}

/** @param list<ExerciseSlot> $slots */
function vpSession(string $name, array $slots): Session
{
    return new Session(vpId('session'), $name, $slots);
}

/** @param list<Session> $sessions */
function vpMeso(
    PhaseType $phase, Priority $priority = Priority::Grow, array $sessions = [], MuscleGroup $muscle = MuscleGroup::Chest,
    bool $resensitization = false,
): Mesocycle {
    return new Mesocycle(vpId('meso'), $phase, 5, $sessions, [$muscle->value => $priority], $resensitization);
}

/** @param array<string, JointPain> $pain keyed by slot id */
function vpAnswers(?Soreness $soreness = null, ?Pump $pump = null, ?VolumeFeedback $volume = null, array $pain = []): FeedbackAnswers
{
    return new FeedbackAnswers($pain, $soreness, $pump, $volume);
}

function vpWorkout(Session $session, FeedbackAnswers $feedback, MuscleGroup $muscle = MuscleGroup::Chest): Workout
{
    return new Workout(vpId('workout'), new DateTimeImmutable('@0'), $session, [$muscle->value => $feedback]);
}

/**
 * Chest sets spread over slots of at most 4, so the 5-set cap per exercise does not interfere with volume tests.
 *
 * @return list<ExerciseSlot>
 */
function vpChestSlots(int $total): array
{
    $left = $total;
    $result = [];
    do {
        $result[] = vpSlot(min($left, 4), MuscleGroup::Chest, 'Press '.count($result));
        $left -= 4;
    } while ($left > 0);

    return $result;
}

function vpChestTotal(Session $session): int
{
    return array_sum(array_map(
        fn (ExerciseSlot $slot) => $slot->muscle === MuscleGroup::Chest ? $slot->sets : 0,
        $session->slots,
    ));
}

/** @return list<int> */
function vpSets(Session $session): array
{
    return array_map(fn (ExerciseSlot $slot) => $slot->sets, $session->slots);
}

/** Chest sessions, no other sessions: the result for one set of answers. */
function vpRun(
    Priority $priority, PhaseType $phase, int $sets, FeedbackAnswers $feedback,
    ExperienceLevel $experience = ExperienceLevel::Intermediate,
): NextOccurrence {
    $session = vpSession('Push', vpChestSlots($sets));

    return VolumeProgression::nextOccurrence(vpWorkout($session, $feedback), vpMeso($phase, $priority, [$session]), $experience);
}

function vpTotal(Priority $priority, PhaseType $phase, int $sets, FeedbackAnswers $feedback): int
{
    return vpChestTotal(vpRun($priority, $phase, $sets, $feedback)->session);
}

function vpStart(MuscleGroup $muscle, Priority $priority, ExperienceLevel $experience, Column $column): int
{
    return VolumeProgression::weeklyStartSets($muscle, $priority, $experience, $column);
}

function vpLandmarks(MuscleGroup $muscle, ExperienceLevel $experience, Column $column): PhaseLandmarks
{
    return VolumeProgression::landmarks($muscle, $experience, $column);
}

function vpCeilingFixture(int $performed, int $other, PhaseType $phase = PhaseType::MuscleGain): NextOccurrence
{
    $a = vpSession('A', vpChestSlots($performed));
    $b = vpSession('B', vpChestSlots($other));

    return VolumeProgression::nextOccurrence(
        vpWorkout($a, vpAnswers(Soreness::NeverGotSore, Pump::Low, VolumeFeedback::NotEnough)), // wants +2
        vpMeso($phase, Priority::Emphasize, [$a, $b]), ExperienceLevel::Intermediate,
    );
}

function vpStrong(): FeedbackAnswers
{
    return vpAnswers(Soreness::NeverGotSore, Pump::Low, VolumeFeedback::NotEnough);
}

function vpNeutral(): FeedbackAnswers
{
    return vpAnswers(Soreness::HealedJustOnTime, Pump::Moderate, VolumeFeedback::JustRight);
}

// PROG-001 / TBL-021

it('PROG-001 the weekly start is MEV for Emphasize and Grow', function () {
    // Chest, intermediate, balanced maintenance: MEV 10.
    foreach ([Priority::Emphasize, Priority::Grow] as $priority) {
        expect(vpStart(MuscleGroup::Chest, $priority, ExperienceLevel::Intermediate, Column::MaintenanceBalanced))->toBe(10);
    }
    expect(vpStart(MuscleGroup::Back, Priority::Grow, ExperienceLevel::Intermediate, Column::MaintenanceBalanced))->toBe(10)
        ->and(vpStart(MuscleGroup::Quads, Priority::Grow, ExperienceLevel::Intermediate, Column::MaintenanceBalanced))->toBe(8);
});

it('PROG-001 experience offsets move MEV and MRV', function () {
    expect(vpLandmarks(MuscleGroup::Chest, ExperienceLevel::Beginner, Column::MaintenanceBalanced))->toEqual(new PhaseLandmarks(8, 8, 18))
        ->and(vpLandmarks(MuscleGroup::Chest, ExperienceLevel::Advanced, Column::MaintenanceBalanced))->toEqual(new PhaseLandmarks(8, 12, 22))
        ->and(vpStart(MuscleGroup::Chest, Priority::Grow, ExperienceLevel::Beginner, Column::MaintenanceBalanced))->toBe(8)
        ->and(vpStart(MuscleGroup::Chest, Priority::Grow, ExperienceLevel::Advanced, Column::MaintenanceBalanced))->toBe(12);
});

it('PROG-001 floors never go below zero and landmarks clamp at zero', function () {
    // Glutes MEV 0; muscle gain MEV -1 and MV -1 both clamp to 0.
    expect(vpLandmarks(MuscleGroup::Glutes, ExperienceLevel::Intermediate, Column::MuscleGain))->toEqual(new PhaseLandmarks(0, 0, 18))
        ->and(vpStart(MuscleGroup::Glutes, Priority::Grow, ExperienceLevel::Intermediate, Column::MuscleGain))->toBe(0)
        // Beginner MEV 0 - 2 clamps to 0; MRV 16 - 4 = 12.
        ->and(vpLandmarks(MuscleGroup::Glutes, ExperienceLevel::Beginner, Column::MaintenanceBalanced))->toEqual(new PhaseLandmarks(0, 0, 12));
});

it('PROG-001 week 1 splits the weekly start across sessions and slots', function () {
    // Chest, muscle gain, intermediate, Grow: 9 sets a week.
    $a1 = vpSlot(0, MuscleGroup::Chest, 'Bench');
    $a2 = vpSlot(0, MuscleGroup::Chest, 'Fly');
    $back = vpSlot(7, MuscleGroup::Back, 'Row');
    $b1 = vpSlot(0, MuscleGroup::Chest, 'Incline');
    $sessionA = vpSession('A', [$a1, $a2, $back]);
    $sessionB = vpSession('B', [$b1]);
    $sessionC = vpSession('C', [vpSlot(4, MuscleGroup::Quads, 'Squat')]);

    $result = VolumeProgression::weekOneSessions(vpMeso(PhaseType::MuscleGain, sessions: [$sessionA, $sessionB, $sessionC]), ExperienceLevel::Intermediate);

    // 9 over two sessions: 5 then 4. Session A splits 5 over two slots: 3 then 2.
    expect(vpSets($result[0]))->toBe([3, 2, 7])
        ->and(vpSets($result[1]))->toBe([4])
        // Muscle without a priority is left unchanged; ids and names kept.
        ->and($result[2])->toEqual($sessionC)
        ->and(array_map(fn (ExerciseSlot $slot) => $slot->id, $result[0]->slots))->toBe([$a1->id, $a2->id, $back->id])
        ->and($result[0]->id)->toBe($sessionA->id)
        ->and($result[0]->name)->toBe('A');
});

it('PROG-001 the week 1 remainder goes to earlier sessions', function () {
    $sessions = array_map(fn (int $i) => vpSession("S{$i}", [vpSlot(0)]), range(0, 3));
    $result = VolumeProgression::weekOneSessions(vpMeso(PhaseType::MuscleGain, sessions: $sessions), ExperienceLevel::Intermediate);
    expect(array_map(fn (Session $s) => $s->slots[0]->sets, $result))->toBe([3, 2, 2, 2]);
});

it('PROG-001 in week 1 each trained session gets at least two sets', function () {
    // 9 over five sessions is 2,2,2,2,1; the floor lifts the last to 2.
    $five = array_map(fn (int $i) => vpSession("S{$i}", [vpSlot(0)]), range(0, 4));
    $result = VolumeProgression::weekOneSessions(vpMeso(PhaseType::MuscleGain, sessions: $five), ExperienceLevel::Intermediate);
    expect(array_map(fn (Session $s) => $s->slots[0]->sets, $result))->toBe([2, 2, 2, 2, 2]);

    // Glutes start at 0 a week; directly trained still means 2 per session.
    $glutes = [vpSession('G', [vpSlot(0, MuscleGroup::Glutes, 'Thrust')])];
    $gluteResult = VolumeProgression::weekOneSessions(
        vpMeso(PhaseType::MuscleGain, sessions: $glutes, muscle: MuscleGroup::Glutes), ExperienceLevel::Intermediate,
    );
    expect($gluteResult[0]->slots[0]->sets)->toBe(2);
});

// PROG-002 Fat loss

it('PROG-002 fat loss raises MEV by two and lowers MRV by two', function () {
    expect(vpLandmarks(MuscleGroup::Chest, ExperienceLevel::Intermediate, Column::FatLoss))->toEqual(new PhaseLandmarks(9, 12, 20))
        ->and(vpStart(MuscleGroup::Chest, Priority::Grow, ExperienceLevel::Intermediate, Column::FatLoss))->toBe(12);
});

it('PROG-002 a mini-cut starts at MEV and caps at MEV plus two', function () {
    expect(vpLandmarks(MuscleGroup::Chest, ExperienceLevel::Intermediate, Column::MiniCut))->toEqual(new PhaseLandmarks(9, 10, 12))
        // Mini-cut ceiling uses the experience-adjusted MEV.
        ->and(vpLandmarks(MuscleGroup::Chest, ExperienceLevel::Advanced, Column::MiniCut))->toEqual(new PhaseLandmarks(9, 12, 14))
        ->and(vpLandmarks(MuscleGroup::Chest, ExperienceLevel::Beginner, Column::MiniCut))->toEqual(new PhaseLandmarks(9, 8, 10));
});

// PROG-003 Muscle gain

it('PROG-003 muscle gain lowers MEV by one and raises MRV by two', function () {
    expect(vpLandmarks(MuscleGroup::Chest, ExperienceLevel::Intermediate, Column::MuscleGain))->toEqual(new PhaseLandmarks(7, 9, 24))
        ->and(vpStart(MuscleGroup::Chest, Priority::Emphasize, ExperienceLevel::Intermediate, Column::MuscleGain))->toBe(9)
        ->and(vpLandmarks(MuscleGroup::Chest, ExperienceLevel::Beginner, Column::MuscleGain))->toEqual(new PhaseLandmarks(7, 7, 20));
});

// PROG-010 Score and wanted change

it('PROG-010 score table points', function () {
    expect(VolumeProgression::score(vpAnswers(Soreness::StillSore)))->toBe(-1.0)
        ->and(VolumeProgression::score(vpAnswers(Soreness::HealedJustOnTime)))->toBe(0.0)
        ->and(VolumeProgression::score(vpAnswers(null, Pump::Amazing)))->toBe(-0.5)
        ->and(VolumeProgression::score(vpAnswers(null, Pump::Moderate)))->toBe(0.0)
        ->and(VolumeProgression::score(vpAnswers(null, Pump::Low)))->toBe(1.0)
        ->and(VolumeProgression::score(vpAnswers(null, null, VolumeFeedback::NotEnough)))->toBe(1.0)
        ->and(VolumeProgression::score(vpAnswers(null, null, VolumeFeedback::JustRight)))->toBe(0.0)
        ->and(VolumeProgression::score(vpAnswers(Soreness::StillSore, Pump::Amazing)))->toBe(-1.5)
        ->and(VolumeProgression::score(vpAnswers(null, Pump::Low, VolumeFeedback::NotEnough)))->toBe(2.0)
        ->and(VolumeProgression::score(vpAnswers()))->toBe(0.0);
});

it('PROG-010 positive soreness counts only with a Low pump or Not enough volume', function () {
    // Gated out.
    expect(VolumeProgression::score(vpAnswers(Soreness::NeverGotSore)))->toBe(0.0)
        ->and(VolumeProgression::score(vpAnswers(Soreness::NeverGotSore, Pump::Moderate, VolumeFeedback::JustRight)))->toBe(0.0)
        ->and(VolumeProgression::score(vpAnswers(Soreness::HealedAWhileAgo, Pump::Amazing, VolumeFeedback::JustRight)))->toBe(-0.5)
        // Counted with a Low pump or Not enough volume.
        ->and(VolumeProgression::score(vpAnswers(Soreness::NeverGotSore, Pump::Low)))->toBe(2.0)
        ->and(VolumeProgression::score(vpAnswers(Soreness::NeverGotSore, Pump::Moderate, VolumeFeedback::NotEnough)))->toBe(2.0)
        ->and(VolumeProgression::score(vpAnswers(Soreness::HealedAWhileAgo, Pump::Low)))->toBe(1.5)
        ->and(VolumeProgression::score(vpAnswers(Soreness::HealedAWhileAgo, null, VolumeFeedback::NotEnough)))->toBe(1.5)
        ->and(VolumeProgression::score(vpAnswers(Soreness::NeverGotSore, Pump::Low, VolumeFeedback::NotEnough)))->toBe(3.0)
        // Negative soreness always counts.
        ->and(VolumeProgression::score(vpAnswers(Soreness::StillSore, Pump::Low)))->toBe(0.0);
});

it('PROG-010 Pushed my limits and Too much add no points', function () {
    expect(VolumeProgression::score(vpAnswers(Soreness::StillSore, null, VolumeFeedback::TooMuch)))->toBe(-1.0)
        ->and(VolumeProgression::score(vpAnswers(Soreness::NeverGotSore, Pump::Low, VolumeFeedback::PushedMyLimits)))->toBe(2.0);
});

it('PROG-010 Emphasize thresholds', function () {
    // Three sets so +2 stays within the 5-set cap of the single exercise.
    $p = Priority::Emphasize;
    $gain = PhaseType::MuscleGain;
    expect(vpTotal($p, $gain, 3, vpAnswers(Soreness::HealedJustOnTime, Pump::Moderate, VolumeFeedback::JustRight)))->toBe(3)
        ->and(vpTotal($p, $gain, 3, vpAnswers(Soreness::HealedJustOnTime, Pump::Low, VolumeFeedback::JustRight)))->toBe(4)
        ->and(vpTotal($p, $gain, 3, vpAnswers(Soreness::HealedAWhileAgo, Pump::Low)))->toBe(4)
        ->and(vpTotal($p, $gain, 3, vpAnswers(Soreness::HealedJustOnTime, Pump::Low, VolumeFeedback::NotEnough)))->toBe(5)
        ->and(vpTotal($p, $gain, 3, vpAnswers(Soreness::NeverGotSore, Pump::Low, VolumeFeedback::NotEnough)))->toBe(5);
});

it('PROG-010 Grow thresholds', function () {
    $p = Priority::Grow;
    $gain = PhaseType::MuscleGain;
    expect(vpTotal($p, $gain, 4, vpAnswers(Soreness::HealedJustOnTime, Pump::Low, VolumeFeedback::JustRight)))->toBe(4)
        ->and(vpTotal($p, $gain, 4, vpAnswers(Soreness::HealedAWhileAgo, Pump::Low)))->toBe(4)
        ->and(vpTotal($p, $gain, 4, vpAnswers(Soreness::HealedJustOnTime, Pump::Low, VolumeFeedback::NotEnough)))->toBe(5)
        ->and(vpTotal($p, $gain, 4, vpAnswers(Soreness::NeverGotSore, Pump::Low, VolumeFeedback::NotEnough)))->toBe(5);
});

it('PROG-010 Maintain never adds', function () {
    expect(vpTotal(Priority::Maintain, PhaseType::MuscleGain, 8, vpStrong()))->toBe(8);
});

it('PROG-010 a score of -1 or less removes one set for every priority', function (Priority $priority) {
    expect(vpTotal($priority, PhaseType::MuscleGain, 6, vpAnswers(Soreness::StillSore, Pump::Moderate, VolumeFeedback::JustRight)))->toBe(5)
        // Score -1.5 still only removes one set.
        ->and(vpTotal($priority, PhaseType::MuscleGain, 6, vpAnswers(Soreness::StillSore, Pump::Amazing)))->toBe(5)
        // Score -0.5 does not remove.
        ->and(vpTotal($priority, PhaseType::MuscleGain, 6, vpAnswers(null, Pump::Amazing)))->toBe(6);
})->with(Priority::cases());

it('PROG-010 no feedback or no priority leaves the muscle unchanged', function () {
    $session = vpSession('Push', [vpSlot(4), vpSlot(3, MuscleGroup::Back, 'Row')]);
    // Chest has a priority but no feedback entry; back has feedback but no priority.
    $backOnly = vpWorkout($session, vpAnswers(Soreness::StillSore), MuscleGroup::Back);
    $result = VolumeProgression::nextOccurrence($backOnly, vpMeso(PhaseType::MuscleGain, sessions: [$session]), ExperienceLevel::Intermediate);
    expect($result->session)->toEqual($session)
        ->and($result->outcomes)->toBe([]);
});

it('PROG-010 feedback changes the same session\'s next occurrence only', function () {
    $push = vpSession('Push', [vpSlot(4)]);
    $pull = vpSession('Pull', [vpSlot(4, MuscleGroup::Chest, 'Fly')]);
    $result = VolumeProgression::nextOccurrence(
        vpWorkout($push, vpStrong()), vpMeso(PhaseType::MuscleGain, Priority::Grow, [$push, $pull]), ExperienceLevel::Intermediate,
    );
    expect($result->session->id)->toBe($push->id)
        ->and($result->session->name)->toBe('Push')
        ->and(array_map(fn (ExerciseSlot $s) => $s->id, $result->session->slots))->toBe(array_map(fn (ExerciseSlot $s) => $s->id, $push->slots))
        ->and(vpSets($result->session))->toBe([5])
        ->and($result->outcomes['chest']->change)->toBe(1);
});

it('PROG-010 a removal keeps at least two sets in the session', function () {
    $stillSore = vpAnswers(Soreness::StillSore);
    expect(vpTotal(Priority::Grow, PhaseType::MuscleGain, 3, $stillSore))->toBe(2)
        ->and(vpTotal(Priority::Grow, PhaseType::MuscleGain, 2, $stillSore))->toBe(2)
        // Already below the floor: never goes up from a removal.
        ->and(vpTotal(Priority::Grow, PhaseType::MuscleGain, 1, $stillSore))->toBe(1)
        ->and(vpTotal(Priority::Maintain, PhaseType::MuscleGain, 2, $stillSore))->toBe(2)
        ->and(vpRun(Priority::Grow, PhaseType::MuscleGain, 2, $stillSore)->outcomes['chest']->change)->toBe(0);
});

it('PROG-010 a removal takes from the worst joint pain, then the latest slot', function () {
    $a = vpSlot(3, MuscleGroup::Chest, 'A');
    $b = vpSlot(3, MuscleGroup::Chest, 'B');
    $session = vpSession('S', [$a, $b]);
    $mesocycle = vpMeso(PhaseType::MuscleGain, sessions: [$session]);
    $next = fn (FeedbackAnswers $answers, ?Session $s = null, ?Mesocycle $m = null) => VolumeProgression::nextOccurrence(
        vpWorkout($s ?? $session, $answers), $m ?? $mesocycle, ExperienceLevel::Intermediate,
    );

    expect(vpSets($next(vpAnswers(Soreness::StillSore, pain: [$b->id => JointPain::Moderate]))->session))->toBe([3, 2])
        ->and(vpSets($next(vpAnswers(Soreness::StillSore, pain: [$a->id => JointPain::Low]))->session))->toBe([2, 3])
        ->and(vpSets($next(vpAnswers(Soreness::StillSore))->session))->toBe([3, 2]);

    // A slot with no sets cannot give one up.
    $empty = vpSlot(0, MuscleGroup::Chest, 'Empty');
    $full = vpSlot(3, MuscleGroup::Chest, 'Full');
    $emptySession = vpSession('E', [$empty, $full]);
    $skipEmpty = $next(
        vpAnswers(Soreness::StillSore, pain: [$empty->id => JointPain::ALot]), $emptySession,
        vpMeso(PhaseType::MuscleGain, sessions: [$emptySession]),
    );
    expect(vpSets($skipEmpty->session))->toBe([0, 2]);
});

// PROG-011 Fat loss add cap

it('PROG-011 fat loss and a mini-cut add at most one set', function () {
    expect(vpTotal(Priority::Emphasize, PhaseType::FatLoss, 3, vpStrong()))->toBe(4)
        ->and(vpTotal(Priority::Emphasize, PhaseType::MiniCut, 3, vpStrong()))->toBe(4)
        // The same answers allow two in muscle gain and balanced maintenance.
        ->and(vpTotal(Priority::Emphasize, PhaseType::MuscleGain, 3, vpStrong()))->toBe(5)
        ->and(vpTotal(Priority::Emphasize, PhaseType::Maintenance, 3, vpStrong()))->toBe(5)
        ->and(vpRun(Priority::Emphasize, PhaseType::FatLoss, 3, vpStrong())->outcomes['chest']->change)->toBe(1);
});

it('PROG-011 resensitization adds nothing', function () {
    // 3 sets is under the 5-set exercise cap and the resensitization ceiling (chest MV 8),
    // so only the TBL-012 cap of 0 can stop the add.
    $session = vpSession('S', [vpSlot(3)]);
    $result = VolumeProgression::nextOccurrence(
        vpWorkout($session, vpStrong()),
        vpMeso(PhaseType::Maintenance, Priority::Emphasize, [$session], resensitization: true), ExperienceLevel::Intermediate,
    );
    expect($result->session->slots[0]->sets)->toBe(3)
        ->and($result->outcomes['chest']->change)->toBe(0)
        ->and($result->outcomes['chest']->atRecoveryLimit)->toBeFalse();
});

// PROG-012 Pushed my limits

it('PROG-012 Pushed my limits stops any increase', function () {
    $pushed = vpAnswers(Soreness::NeverGotSore, Pump::Low, VolumeFeedback::PushedMyLimits); // score 2
    expect(vpTotal(Priority::Grow, PhaseType::MuscleGain, 4, $pushed))->toBe(4)
        ->and(vpTotal(Priority::Emphasize, PhaseType::MuscleGain, 4, $pushed))->toBe(4)
        ->and(vpRun(Priority::Emphasize, PhaseType::MuscleGain, 4, $pushed)->outcomes['chest']->change)->toBe(0);
});

it('PROG-012 Pushed my limits still allows a removal by score', function () {
    expect(vpTotal(Priority::Grow, PhaseType::MuscleGain, 6, vpAnswers(Soreness::StillSore, Pump::Moderate, VolumeFeedback::PushedMyLimits)))->toBe(5);
});

// PROG-013 Too much

it('PROG-013 Too much removes exactly one set, whatever else', function () {
    // Score would be +2 (would add).
    expect(vpTotal(Priority::Emphasize, PhaseType::MuscleGain, 6, vpAnswers(Soreness::NeverGotSore, Pump::Low, VolumeFeedback::TooMuch)))->toBe(5)
        // Score -1.5 would remove one; Too much still removes exactly one.
        ->and(vpTotal(Priority::Grow, PhaseType::MuscleGain, 6, vpAnswers(Soreness::StillSore, Pump::Amazing, VolumeFeedback::TooMuch)))->toBe(5)
        // Neutral score still removes.
        ->and(vpTotal(Priority::Grow, PhaseType::MuscleGain, 6, vpAnswers(Soreness::HealedJustOnTime, Pump::Moderate, VolumeFeedback::TooMuch)))->toBe(5)
        ->and(vpTotal(Priority::Maintain, PhaseType::MuscleGain, 6, vpAnswers(null, null, VolumeFeedback::TooMuch)))->toBe(5);
});

// PROG-014 Dr. Mike's examples

it('PROG-014 Grow, muscle gain, 6 sets, still sore, moderate pump, just right gives 5', function () {
    $feedback = vpAnswers(Soreness::StillSore, Pump::Moderate, VolumeFeedback::JustRight);
    expect(VolumeProgression::score($feedback))->toBe(-1.0)
        ->and(vpTotal(Priority::Grow, PhaseType::MuscleGain, 6, $feedback))->toBe(5);
});

it('PROG-014 Grow, muscle gain, 3 sets, never sore, low pump, just right gives 4', function () {
    $feedback = vpAnswers(Soreness::NeverGotSore, Pump::Low, VolumeFeedback::JustRight);
    expect(VolumeProgression::score($feedback))->toBe(2.0)
        ->and(vpTotal(Priority::Grow, PhaseType::MuscleGain, 3, $feedback))->toBe(4);
});

it('PROG-014 the same answers at Emphasize give 5 in muscle gain', function () {
    expect(vpTotal(Priority::Emphasize, PhaseType::MuscleGain, 3, vpAnswers(Soreness::NeverGotSore, Pump::Low, VolumeFeedback::JustRight)))->toBe(5);
});

it('PROG-014 the same answers at Emphasize give 4 in fat loss', function () {
    expect(vpTotal(Priority::Emphasize, PhaseType::FatLoss, 3, vpAnswers(Soreness::NeverGotSore, Pump::Low, VolumeFeedback::JustRight)))->toBe(4);
});

// PROG-015 Ceiling

it('PROG-015 adds are trimmed to the phase-adjusted MRV', function () {
    // Muscle gain chest MRV 24; 13 + 10 = 23 so one set fits.
    $partly = vpCeilingFixture(performed: 13, other: 10);
    expect(vpChestTotal($partly->session))->toBe(14)
        ->and($partly->outcomes['chest']->change)->toBe(1)
        ->and($partly->outcomes['chest']->atRecoveryLimit)->toBeTrue();
});

it('PROG-015 at the ceiling volume holds and reports the recovery limit', function () {
    $held = vpCeilingFixture(performed: 14, other: 10);
    expect(vpChestTotal($held->session))->toBe(14)
        ->and($held->outcomes['chest']->change)->toBe(0)
        ->and($held->outcomes['chest']->atRecoveryLimit)->toBeTrue()
        ->and($held->outcomes['chest']->recoveryMessage)->toBe('At your recovery limit for Chest');
});

it('PROG-015 below the ceiling reports nothing', function () {
    $free = vpCeilingFixture(performed: 10, other: 10);
    expect(vpChestTotal($free->session))->toBe(12)
        ->and($free->outcomes['chest']->atRecoveryLimit)->toBeFalse()
        ->and($free->outcomes['chest']->recoveryMessage)->toBeNull();
    // Exactly reaching MRV is not a cut add.
    $exact = vpCeilingFixture(performed: 12, other: 10);
    expect(vpChestTotal($exact->session))->toBe(14)
        ->and($exact->outcomes['chest']->atRecoveryLimit)->toBeFalse();
});

it('PROG-015 existing excess is not trimmed, and no add wanted reports nothing', function () {
    $a = vpSession('A', vpChestSlots(20));
    $b = vpSession('B', vpChestSlots(10));
    $mesocycle = vpMeso(PhaseType::MuscleGain, Priority::Emphasize, [$a, $b]);
    $neutral = VolumeProgression::nextOccurrence(vpWorkout($a, vpNeutral()), $mesocycle, ExperienceLevel::Intermediate);
    expect(vpChestTotal($neutral->session))->toBe(20)
        ->and($neutral->outcomes['chest']->atRecoveryLimit)->toBeFalse();
    // A removal still applies above the ceiling.
    $sore = VolumeProgression::nextOccurrence(vpWorkout($a, vpAnswers(Soreness::StillSore)), $mesocycle, ExperienceLevel::Intermediate);
    expect(vpChestTotal($sore->session))->toBe(19);
});

it('PROG-015 the mini-cut ceiling is MEV plus two', function () {
    // Intermediate mini-cut chest MRV is 12.
    $atCeiling = vpRun(Priority::Emphasize, PhaseType::MiniCut, 12, vpStrong());
    expect(vpChestTotal($atCeiling->session))->toBe(12)
        ->and($atCeiling->outcomes['chest']->atRecoveryLimit)->toBeTrue();
    $below = vpRun(Priority::Emphasize, PhaseType::MiniCut, 11, vpStrong());
    expect(vpChestTotal($below->session))->toBe(12)
        ->and($below->outcomes['chest']->atRecoveryLimit)->toBeFalse();
});

it('PROG-015 the ceiling follows the experience level', function () {
    // Muscle gain chest MRV: intermediate 24, beginner 20.
    expect(vpChestTotal(vpRun(Priority::Emphasize, PhaseType::MuscleGain, 20, vpStrong(), ExperienceLevel::Intermediate)->session))->toBe(22);
    $beginner = vpRun(Priority::Emphasize, PhaseType::MuscleGain, 20, vpStrong(), ExperienceLevel::Beginner);
    expect(vpChestTotal($beginner->session))->toBe(20)
        ->and($beginner->outcomes['chest']->atRecoveryLimit)->toBeTrue();
});

// PROG-016 Maintain

it('PROG-016 Maintain starts at the phase-adjusted MV', function (Column $column, int $start) {
    expect(vpStart(MuscleGroup::Chest, Priority::Maintain, ExperienceLevel::Intermediate, $column))->toBe($start);
})->with([
    [Column::MaintenanceBalanced, 8],
    [Column::FatLoss, 9],
    [Column::MiniCut, 9],
    [Column::MuscleGain, 7],
    [Column::MaintenanceResensitization, 8],
]);

it('PROG-016 Maintain week 1 sessions use MV', function () {
    $sessions = [vpSession('A', [vpSlot(0)]), vpSession('B', [vpSlot(0)])];
    $result = VolumeProgression::weekOneSessions(vpMeso(PhaseType::MuscleGain, Priority::Maintain, $sessions), ExperienceLevel::Intermediate);
    // MV 7 in muscle gain over two sessions: 4 then 3.
    expect(array_map(fn (Session $s) => $s->slots[0]->sets, $result))->toBe([4, 3]);
});

// PROG-017 Placement

it('PROG-017 added sets go to the best joint feedback, up to five', function () {
    $a = vpSlot(3, MuscleGroup::Chest, 'A');
    $b = vpSlot(4, MuscleGroup::Chest, 'B');
    $session = vpSession('S', [$a, $b]);
    $result = VolumeProgression::nextOccurrence(
        vpWorkout($session, vpAnswers(Soreness::NeverGotSore, Pump::Low, VolumeFeedback::NotEnough, [$a->id => JointPain::Low])),
        vpMeso(PhaseType::MuscleGain, Priority::Emphasize, [$session]), ExperienceLevel::Intermediate,
    );
    // First set to B (no pain), the next finds B full and goes to A.
    expect(vpSets($result->session))->toBe([4, 5])
        ->and($result->outcomes['chest']->change)->toBe(2);
});

it('PROG-017 ties go to the earliest slot and a missing answer counts as none', function () {
    $a = vpSlot(3, MuscleGroup::Chest, 'A');
    $b = vpSlot(3, MuscleGroup::Chest, 'B');
    $session = vpSession('S', [$a, $b]);
    $grow = vpMeso(PhaseType::MuscleGain, Priority::Grow, [$session]);
    $next = fn (array $pain) => VolumeProgression::nextOccurrence(
        vpWorkout($session, vpAnswers(Soreness::NeverGotSore, Pump::Low, VolumeFeedback::NotEnough, $pain)), $grow, ExperienceLevel::Intermediate,
    );

    expect(vpSets($next([])->session))->toBe([4, 3])
        ->and(vpSets($next([$a->id => JointPain::None, $b->id => JointPain::None])->session))->toBe([4, 3])
        ->and(vpSets($next([$a->id => JointPain::Low])->session))->toBe([3, 4]);
});

it('PROG-017 no exercise goes past five sets', function () {
    expect(VolumeProgression::MAX_SETS_PER_EXERCISE_PER_SESSION)->toBe(5);
    $single = vpRun(Priority::Emphasize, PhaseType::MuscleGain, 4, vpStrong());
    expect($single->session->slots[0]->sets)->toBe(5)
        ->and($single->outcomes['chest']->change)->toBe(1)
        ->and($single->outcomes['chest']->suggestsAddingExercise)->toBeTrue();
});

it('PROG-017 suggests adding an exercise when every exercise is at five', function () {
    $a = vpSlot(5, MuscleGroup::Chest, 'A');
    $b = vpSlot(5, MuscleGroup::Chest, 'B');
    $session = vpSession('S', [$a, $b]);
    $full = VolumeProgression::nextOccurrence(
        vpWorkout($session, vpStrong()), vpMeso(PhaseType::MuscleGain, Priority::Grow, [$session]), ExperienceLevel::Intermediate,
    );
    expect(vpSets($full->session))->toBe([5, 5])
        ->and($full->outcomes['chest']->change)->toBe(0)
        ->and($full->outcomes['chest']->suggestsAddingExercise)->toBeTrue();

    // No add wanted: no suggestion.
    $quiet = VolumeProgression::nextOccurrence(
        vpWorkout($session, vpNeutral()), vpMeso(PhaseType::MuscleGain, Priority::Grow, [$session]), ExperienceLevel::Intermediate,
    );
    expect($quiet->outcomes['chest']->suggestsAddingExercise)->toBeFalse();

    // Room left in one slot: no suggestion.
    $c = vpSlot(4, MuscleGroup::Chest, 'C');
    $roomy = vpSession('R', [$a, $c]);
    $result = VolumeProgression::nextOccurrence(
        vpWorkout($roomy, vpStrong()), vpMeso(PhaseType::MuscleGain, Priority::Grow, [$roomy]), ExperienceLevel::Intermediate,
    );
    expect(vpSets($result->session))->toBe([5, 5])
        ->and($result->outcomes['chest']->suggestsAddingExercise)->toBeFalse();
});

// PROG-018 Joint pain

it('PROG-018 Moderate or A lot of pain stops added sets on that exercise', function (JointPain $pain) {
    $a = vpSlot(3, MuscleGroup::Chest, 'A');
    $b = vpSlot(4, MuscleGroup::Chest, 'B');
    $session = vpSession('S', [$a, $b]);
    $result = VolumeProgression::nextOccurrence(
        vpWorkout($session, vpAnswers(Soreness::NeverGotSore, Pump::Low, VolumeFeedback::NotEnough, [$a->id => $pain])),
        vpMeso(PhaseType::MuscleGain, Priority::Emphasize, [$session]), ExperienceLevel::Intermediate,
    );
    // B takes one set and reaches five; the second set cannot go to A.
    expect(vpSets($result->session))->toBe([3, 5])
        ->and($result->outcomes['chest']->change)->toBe(1);
})->with([JointPain::Moderate, JointPain::ALot]);

it('PROG-018 added sets are dropped when no exercise can take them', function () {
    $a = vpSlot(3, MuscleGroup::Chest, 'A');
    $session = vpSession('S', [$a]);
    $result = VolumeProgression::nextOccurrence(
        vpWorkout($session, vpAnswers(Soreness::NeverGotSore, Pump::Low, VolumeFeedback::NotEnough, [$a->id => JointPain::Moderate])),
        vpMeso(PhaseType::MuscleGain, Priority::Emphasize, [$session]), ExperienceLevel::Intermediate,
    );
    expect($result->session->slots[0]->sets)->toBe(3)
        ->and($result->outcomes['chest']->change)->toBe(0)
        ->and($result->outcomes['chest']->suggestsAddingExercise)->toBeFalse();
});

it('PROG-018 A lot of pain cuts the next load by ten percent', function () {
    expect(VolumeProgression::LOAD_CUT_PERCENT)->toBe(10);
    $a = vpSlot(3, MuscleGroup::Chest, 'A');
    $b = vpSlot(3, MuscleGroup::Chest, 'B');
    $c = vpSlot(3, MuscleGroup::Chest, 'C');
    $session = vpSession('S', [$a, $b, $c]);
    $result = VolumeProgression::nextOccurrence(
        vpWorkout($session, vpAnswers(Soreness::HealedJustOnTime, Pump::Moderate, VolumeFeedback::JustRight, [
            $a->id => JointPain::ALot, $b->id => JointPain::Moderate, $c->id => JointPain::Low,
        ])),
        vpMeso(PhaseType::MuscleGain, Priority::Grow, [$session]), ExperienceLevel::Intermediate,
    );
    expect($result->outcomes['chest']->loadCutSlotIds)->toBe([$a->id])
        ->and(vpSets($result->session))->toBe([3, 3, 3]);
});

// PROG-019 Manual sets

it('PROG-019 add and remove sets by hand', function () {
    $a = vpSlot(3, MuscleGroup::Chest, 'A');
    $b = vpSlot(3, MuscleGroup::Chest, 'B');
    $first = vpSession('One', [$a, $b]);
    $second = vpSession('Two', [vpSlot(4, MuscleGroup::Chest, 'C')]);
    $mesocycle = vpMeso(PhaseType::MuscleGain, sessions: [$first, $second]);

    $added = VolumeProgression::adjustSets($mesocycle, $first->id, $b->id, 2);
    expect(vpSets($added->sessions[0]))->toBe([3, 5])
        ->and($added->sessions[1])->toEqual($second)
        ->and(vpSets(VolumeProgression::adjustSets($mesocycle, $first->id, $a->id, -1)->sessions[0]))->toBe([2, 3])
        // Never below zero.
        ->and(vpSets(VolumeProgression::adjustSets($mesocycle, $first->id, $a->id, -9)->sessions[0]))->toBe([0, 3])
        // Manual adds are not capped at five per exercise or at the MRV.
        ->and(vpSets(VolumeProgression::adjustSets($mesocycle, $first->id, $a->id, 30)->sessions[0]))->toBe([33, 3])
        // Unknown ids change nothing.
        ->and(VolumeProgression::adjustSets($mesocycle, 'unknown', $a->id, 1))->toEqual($mesocycle)
        ->and(VolumeProgression::adjustSets($mesocycle, $first->id, 'unknown', 1))->toEqual($mesocycle);
});

it('PROG-019 a set edit keeps the rest of the mesocycle', function () {
    $press = vpSlot(3, MuscleGroup::Chest, 'Press');
    $row = vpSlot(2, MuscleGroup::Back, 'Row');
    $session = vpSession('Upper', [$press, $row]);
    $earlier = vpSession('Old upper', [vpSlot(4)]);
    $before = new Mesocycle(
        'meso-kept', PhaseType::Maintenance, 7, [$session], [MuscleGroup::Chest->value => Priority::Emphasize], true,
        [[$earlier]], [[$session->id]], new DateTimeImmutable('2026-03-01'),
    );

    $after = VolumeProgression::adjustSets($before, $session->id, $row->id, 1);

    // Only the one slot's count moves; every other field, the slot's name and muscle included, is carried over.
    $edited = new Session($session->id, 'Upper', [$press, new ExerciseSlot($row->id, 'Row', MuscleGroup::Back, 3)]);
    expect($after)->toEqual(new Mesocycle(
        'meso-kept', PhaseType::Maintenance, 7, [$edited], [MuscleGroup::Chest->value => Priority::Emphasize], true,
        [[$earlier]], [[$session->id]], new DateTimeImmutable('2026-03-01'),
    ));
    foreach (get_object_vars($before) as $property => $value) {
        if ($property !== 'sessions') {
            expect($after->{$property})->toEqual($value, $property);
        }
    }
});

it('PROG-019 added sets carry forward and removed sets do not come back', function () {
    $original = vpSession('S', [vpSlot(3), vpSlot(1, MuscleGroup::Chest, 'B')]);
    $base = vpMeso(PhaseType::MuscleGain, sessions: [$original]);
    $slotId = $original->slots[0]->id;

    $up = VolumeProgression::adjustSets($base, $original->id, $slotId, 2);
    $carried = VolumeProgression::nextOccurrence(vpWorkout($up->sessions[0], vpNeutral()), $up, ExperienceLevel::Intermediate);
    expect(vpSets($carried->session))->toBe([5, 1]);

    $down = VolumeProgression::adjustSets($base, $original->id, $slotId, -1);
    $dropped = VolumeProgression::nextOccurrence(vpWorkout($down->sessions[0], vpNeutral()), $down, ExperienceLevel::Intermediate);
    expect(vpSets($dropped->session))->toBe([2, 1]);

    // Feedback builds on the edited count.
    $grown = VolumeProgression::nextOccurrence(
        vpWorkout($up->sessions[0], vpAnswers(Soreness::NeverGotSore, Pump::Low, VolumeFeedback::JustRight)), $up, ExperienceLevel::Intermediate,
    );
    // First exercise is full at 5, so the extra set goes to the second.
    expect(vpSets($grown->session))->toBe([5, 2]);
});
