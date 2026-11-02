<?php

use App\Domain\Defaults\TBL012TrainingDefaults\Column;
use App\Domain\Mesocycle\MesoBuilder;
use App\Domain\Mesocycle\MesocycleRules;
use App\Domain\Model\ExerciseSlot;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\FeedbackAnswers;
use App\Domain\Model\Mesocycle;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Priority;
use App\Domain\Model\Pump;
use App\Domain\Model\Session;
use App\Domain\Model\Soreness;
use App\Domain\Model\VolumeFeedback;
use App\Domain\Model\Workout;
use App\Domain\Progression\VolumeProgression;
use App\Enums\PhaseFit;

// The create flow's rules, value in and value out: day labels (MESO-005), the slots an Off muscle leaves
// (MESO-034), what a priority does to volume (MESO-031) and a phase's template fit (MESO-011).

/** @return list<array{id: string, label: ?string, weekday: ?int, slots: list<mixed>}> */
function labelledDays(int $count): array
{
    return array_map(fn (int $i) => ['id' => "d{$i}", 'label' => "Full Body {$i}", 'weekday' => 3, 'slots' => []], range(1, $count));
}

it('MESO-005 weekday labels map the days to the training days in plan-week order', function () {
    $days = MesocycleRules::labelDays(labelledDays(3), 'weekdays', [2, 4, 6]);

    expect(array_column($days, 'weekday'))->toBe([2, 4, 6])
        // A weekday day reads as its weekday's name, so no label is stored.
        ->and(array_column($days, 'label'))->toBe([null, null, null])
        ->and(array_column($days, 'id'))->toBe(['d1', 'd2', 'd3']);
});

it('MESO-005 a floating split numbers its days and has no weekdays', function () {
    $days = MesocycleRules::labelDays(labelledDays(2), 'floating', [2, 4]);

    expect(array_column($days, 'weekday'))->toBe([null, null])
        ->and(array_column($days, 'label'))->toBe([null, null]);
});

it('MESO-005 weekday names need one training day per session, else the days float', function () {
    expect(array_column(MesocycleRules::labelDays(labelledDays(3), 'weekdays', [1, 4]), 'weekday'))->toBe([null, null, null])
        ->and(array_column(MesocycleRules::labelDays(labelledDays(2), 'weekdays', []), 'weekday'))->toBe([null, null])
        ->and(MesocycleRules::canUseWeekdays(2, [1, 4]))->toBeTrue()
        ->and(MesocycleRules::canUseWeekdays(0, []))->toBeFalse();
});

it('MESO-034 an Off muscle\'s slots are left out of every session', function () {
    $upper = new Session('upper', 'Upper', [
        new ExerciseSlot('s1', 'Bench Press', MuscleGroup::Chest, 3),
        new ExerciseSlot('s2', 'Curl', MuscleGroup::Biceps, 2),
    ]);
    $arms = new Session('arms', 'Arms', [new ExerciseSlot('s3', 'Hammer Curl', MuscleGroup::Biceps, 3)]);

    $sessions = MesocycleRules::withoutOff([$upper, $arms], [MuscleGroup::Biceps]);

    expect(array_map(fn (ExerciseSlot $s) => $s->id, $sessions[0]->slots))->toBe(['s1'])
        // A session with no slots left plans no sets; it keeps its place so the days keep their numbers.
        ->and($sessions[1]->id)->toBe('arms')
        ->and($sessions[1]->slots)->toBe([])
        ->and(MesocycleRules::withoutOff([$upper], []))->toBe([$upper]);
});

it('MESO-034 MESO-023 MESO-021 an Off muscle is not trained by the builder and does not count for the warnings', function () {
    $priorities = MesoBuilder::defaultPriorities(Column::for(PhaseType::FatLoss, false));
    unset($priorities['biceps']);
    $sessions = MesoBuilder::sessions(3, $priorities, Column::for(PhaseType::FatLoss, false), fn () => uniqid());
    $muscles = array_merge(...array_map(fn (Session $s) => array_map(fn (ExerciseSlot $slot) => $slot->muscle, $s->slots), $sessions));

    expect($muscles)->not->toContain(MuscleGroup::Biceps)
        // Every other muscle is at Emphasize in fat loss, so leaving one off is not specialising.
        ->and(MesoBuilder::warnings($priorities, PhaseType::FatLoss))->toBe([]);
});

it('MESO-022 a generated slot starts at 2 sets', function () {
    expect(MesoBuilder::DEFAULT_SETS)->toBe(2);
});

it('MESO-031 Emphasize adds volume when recovery allows, Grow only when feedback asks for it, Maintain holds', function () {
    $next = function (Priority $priority, FeedbackAnswers $answers): int {
        $session = new Session('push', 'Push', [new ExerciseSlot('press', 'Press', MuscleGroup::Chest, 3)]);
        $mesocycle = new Mesocycle('m', PhaseType::MuscleGain, 5, [$session], ['chest' => $priority]);
        $workout = new Workout('w', new DateTimeImmutable('@0'), $session, ['chest' => $answers]);

        return VolumeProgression::nextOccurrence($workout, $mesocycle, ExperienceLevel::Intermediate)->session->slots[0]->sets;
    };
    // Recovered on time with a low pump: room to grow, but nobody asked for more.
    $recovered = new FeedbackAnswers([], Soreness::HealedJustOnTime, Pump::Low, VolumeFeedback::JustRight);
    // Recovered and asking for more.
    $wantsMore = new FeedbackAnswers([], Soreness::HealedJustOnTime, Pump::Low, VolumeFeedback::NotEnough);

    expect($next(Priority::Emphasize, $recovered))->toBe(4)
        ->and($next(Priority::Grow, $recovered))->toBe(3)
        ->and($next(Priority::Grow, $wantsMore))->toBe(4)
        ->and($next(Priority::Maintain, $wantsMore))->toBe(3);
});

it('MESO-011 a phase fits cut, gain or maintain templates; a mini-cut is a cut', function () {
    expect(PhaseFit::for(PhaseType::FatLoss))->toBe(PhaseFit::Cut)
        ->and(PhaseFit::for(PhaseType::MiniCut))->toBe(PhaseFit::Cut)
        ->and(PhaseFit::for(PhaseType::MuscleGain))->toBe(PhaseFit::Gain)
        ->and(PhaseFit::for(PhaseType::Maintenance))->toBe(PhaseFit::Maintain);
});
