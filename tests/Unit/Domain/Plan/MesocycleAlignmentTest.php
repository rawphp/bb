<?php

use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\PhaseType;
use App\Domain\Plan\MesocycleAlignment;
use App\Domain\Plan\MesocycleLengthChange;
use App\Domain\Plan\MesocycleProposal;
use App\Domain\Plan\PhaseEndFixes;
use App\Domain\Plan\PlannedMesocycle;
use App\Domain\Plan\WeekRange;

function proposal(PhaseType $type, ?int $weeks, ExperienceLevel $experience): MesocycleProposal
{
    return MesocycleAlignment::proposal($type, $weeks, $experience);
}

/**
 * @param  list<array{int, int}>  $specs  start week and weeks
 * @return list<PlannedMesocycle>
 */
function plannedMesocycles(array $specs): array
{
    return array_map(fn (array $spec) => new PlannedMesocycle(startWeek: $spec[0], weeks: $spec[1]), $specs);
}

function phaseEndFixes(int $from, int $to, PhaseType $type, array $specs, ExperienceLevel $experience): ?PhaseEndFixes
{
    return MesocycleAlignment::phaseEndFixes(new WeekRange($from, $to), $type, plannedMesocycles($specs), $experience);
}

it('PLAN-020 planned mesocycle weeks', function () {
    $mesocycle = new PlannedMesocycle(startWeek: 4, weeks: 5);

    expect($mesocycle->deloadWeek())->toBe(8)
        ->and($mesocycle->nextStartWeek())->toBe(9)
        ->and($mesocycle->contains(3))->toBeFalse()
        ->and($mesocycle->contains(4))->toBeTrue()
        ->and($mesocycle->contains(8))->toBeTrue()
        ->and($mesocycle->contains(9))->toBeFalse();
});

it('PLAN-020 a phase is filled with mesocycles ending on a deload', function () {
    expect(proposal(PhaseType::MuscleGain, 10, ExperienceLevel::Intermediate))->toEqual(new MesocycleProposal([5, 5], true))
        ->and(proposal(PhaseType::MuscleGain, 16, ExperienceLevel::Intermediate))->toEqual(new MesocycleProposal([6, 5, 5], true))
        ->and(proposal(PhaseType::MuscleGain, 12, ExperienceLevel::Beginner))->toEqual(new MesocycleProposal([6, 6], true));
});

it('PLAN-020 equal costs go to fewer mesocycles', function () {
    // [8, 7] and [5, 5, 5] are both 3 weeks off the beginner default of 6.
    expect(proposal(PhaseType::MuscleGain, 15, ExperienceLevel::Beginner))->toEqual(new MesocycleProposal([8, 7], true));
});

it('PLAN-020 a short phase gets one mesocycle inside the table range', function () {
    expect(proposal(PhaseType::MuscleGain, 6, ExperienceLevel::Intermediate))->toEqual(new MesocycleProposal([6], true))
        ->and(proposal(PhaseType::Maintenance, 8, ExperienceLevel::Intermediate))->toEqual(new MesocycleProposal([8], true))
        ->and(proposal(PhaseType::MuscleGain, 7, ExperienceLevel::Advanced))->toEqual(new MesocycleProposal([7], true));
});

it('PLAN-020 a phase that cannot be filled runs past its end', function () {
    // Intermediate 3 weeks: the shortest mesocycle is 5, so the last one runs past the phase.
    expect(proposal(PhaseType::MuscleGain, 3, ExperienceLevel::Intermediate))->toEqual(new MesocycleProposal([5], false))
        // Intermediate fat loss 9: no mix of 5 and 6 makes 9, so 10 is used.
        ->and(proposal(PhaseType::FatLoss, 9, ExperienceLevel::Intermediate))->toEqual(new MesocycleProposal([5, 5], false));
});

it('PLAN-020 an open-ended phase gets one default mesocycle at a time', function () {
    expect(proposal(PhaseType::Maintenance, null, ExperienceLevel::Intermediate))->toEqual(new MesocycleProposal([5], false))
        ->and(proposal(PhaseType::Maintenance, null, ExperienceLevel::Beginner))->toEqual(new MesocycleProposal([6], false))
        ->and(proposal(PhaseType::Maintenance, null, ExperienceLevel::Advanced))->toEqual(new MesocycleProposal([5], false));
});

it('PLAN-020 mesocycles are laid end to end from the start week', function () {
    $layout = MesocycleAlignment::mesocycles(new MesocycleProposal([6, 5, 5], true), startingAtWeek: 3);

    expect($layout)->toEqual(plannedMesocycles([[3, 6], [9, 5], [14, 5]]))
        ->and($layout[2]->deloadWeek())->toBe(18);
});

it('PLAN-026 a fat-loss phase keeps deloads four to six weeks apart', function () {
    expect(proposal(PhaseType::FatLoss, 12, ExperienceLevel::Intermediate))->toEqual(new MesocycleProposal([6, 6], true))
        ->and(proposal(PhaseType::FatLoss, 9, ExperienceLevel::Advanced))->toEqual(new MesocycleProposal([5, 4], true))
        ->and(proposal(PhaseType::MiniCut, 12, ExperienceLevel::Intermediate))->toEqual(new MesocycleProposal([6, 6], true));
});

it('PLAN-026 a fat-loss phase under nine weeks may be one longer mesocycle', function () {
    expect(proposal(PhaseType::FatLoss, 8, ExperienceLevel::Intermediate))->toEqual(new MesocycleProposal([8], true))
        ->and(proposal(PhaseType::FatLoss, 3, ExperienceLevel::Intermediate))->toEqual(new MesocycleProposal([5], false));
});

it('PLAN-026 a fat-loss phase over eight weeks has a deload at maintenance before its end', function () {
    foreach ([PhaseType::FatLoss, PhaseType::MiniCut] as $type) {
        foreach (ExperienceLevel::cases() as $experience) {
            foreach (range(9, 26) as $weeks) {
                $result = proposal($type, $weeks, $experience);
                $label = "{$type->value} {$experience->value} {$weeks}";
                $total = array_sum($result->lengths);
                expect(count($result->lengths))->toBeGreaterThanOrEqual(2, $label)
                    ->and(array_filter($result->lengths, fn (int $length) => $length < 4 || $length > 6))->toBe([], $label)
                    ->and($total)->toBeGreaterThanOrEqual($weeks, $label)
                    ->and($result->endsOnDeload)->toBe($total === $weeks, $label);
            }
        }
    }
});

it('PLAN-026 other phases may use the whole table range', function () {
    expect(proposal(PhaseType::MuscleGain, 8, ExperienceLevel::Intermediate))->toEqual(new MesocycleProposal([8], true))
        ->and(proposal(PhaseType::Maintenance, 16, ExperienceLevel::Intermediate))->toEqual(new MesocycleProposal([6, 5, 5], true));
});

// PLAN-021: phase end mid-mesocycle.

it('PLAN-021 both fixes are offered', function () {
    // The nearest deload (week 11) and a 5-week mesocycle for the last one.
    expect(phaseEndFixes(0, 11, PhaseType::MuscleGain, [[0, 6], [6, 6]], ExperienceLevel::Intermediate))
        ->toEqual(new PhaseEndFixes(movedEndWeek: 12, mesocycleLength: new MesocycleLengthChange(index: 1, weeks: 5)));
});

it('PLAN-021 a phase ending on a deload is aligned', function () {
    expect(phaseEndFixes(0, 10, PhaseType::MuscleGain, [[0, 5], [5, 5]], ExperienceLevel::Intermediate))->toBeNull();
});

it('PLAN-021 a phase ending beyond the mesocycles has nothing to fix', function () {
    expect(phaseEndFixes(0, 8, PhaseType::MuscleGain, [[0, 5]], ExperienceLevel::Intermediate))->toBeNull();
});

it('PLAN-021 the nearest deload wins within two weeks', function () {
    $layout = [[0, 5], [5, 5], [10, 5]];

    // Deloads at 4, 9, 14; last week 11: week 9 is 2 away, week 14 is 3 away.
    expect(phaseEndFixes(0, 12, PhaseType::MuscleGain, $layout, ExperienceLevel::Intermediate)?->movedEndWeek)->toBe(10)
        // Last week 12: week 14 is 2 away, week 9 is 3 away.
        ->and(phaseEndFixes(0, 13, PhaseType::MuscleGain, $layout, ExperienceLevel::Intermediate)?->movedEndWeek)->toBe(15)
        // Last week 10 is one away from the deload at 9 and four from 14.
        ->and(phaseEndFixes(0, 11, PhaseType::MuscleGain, $layout, ExperienceLevel::Intermediate)?->movedEndWeek)->toBe(10);
});

it('PLAN-021 a tie between two deloads goes to the later one', function () {
    // Deloads at 4, 8, 13; last week 6 is 2 from both 4 and 8.
    expect(phaseEndFixes(0, 7, PhaseType::MuscleGain, [[0, 5], [5, 4], [9, 5]], ExperienceLevel::Intermediate)?->movedEndWeek)
        ->toBe(9);
});

it('PLAN-021 no deload within two weeks leaves only the mesocycle fix', function () {
    // One 8-week mesocycle with its deload in week 7; the phase ends after week 4.
    expect(phaseEndFixes(0, 5, PhaseType::MuscleGain, [[0, 8]], ExperienceLevel::Intermediate))
        ->toEqual(new PhaseEndFixes(movedEndWeek: null, mesocycleLength: new MesocycleLengthChange(index: 0, weeks: 5)));
});

it('PLAN-021 a mesocycle length outside the table is not offered', function () {
    // Phase ends after week 3: a 4-week mesocycle is below the intermediate 5 to 8 range, and no deload is near.
    expect(phaseEndFixes(0, 4, PhaseType::MuscleGain, [[0, 8]], ExperienceLevel::Intermediate))
        ->toEqual(new PhaseEndFixes(movedEndWeek: null, mesocycleLength: null))
        // The same cut is fine for an advanced lifter (4 to 7).
        ->and(phaseEndFixes(0, 4, PhaseType::MuscleGain, [[0, 7]], ExperienceLevel::Advanced))
        ->toEqual(new PhaseEndFixes(movedEndWeek: null, mesocycleLength: new MesocycleLengthChange(index: 0, weeks: 4)));
});

it('PLAN-021 phase limits block moving the end out', function () {
    // Muscle gain is capped at 16 weeks: moving the end to the deload in week 17 would make 18.
    $gain = phaseEndFixes(0, 16, PhaseType::MuscleGain, [[0, 6], [6, 6], [12, 6], [18, 6]], ExperienceLevel::Advanced);

    expect($gain?->movedEndWeek)->toBeNull()
        ->and($gain?->mesocycleLength)->toEqual(new MesocycleLengthChange(index: 2, weeks: 4));
});

it('PLAN-021 diet breaks count towards the fat-loss limit', function () {
    // Same layout as a fat loss phase: 18 weeks with 3 diet breaks is 15 deficit weeks, inside the cap.
    $fatLoss = phaseEndFixes(0, 16, PhaseType::FatLoss, [[0, 6], [6, 6], [12, 6], [18, 6]], ExperienceLevel::Advanced);

    expect($fatLoss?->movedEndWeek)->toBe(18)
        ->and($fatLoss?->mesocycleLength)->toEqual(new MesocycleLengthChange(index: 2, weeks: 4));
});

it('PLAN-021 a phase not starting at week zero measures its own length', function () {
    // Muscle gain weeks 20 to 29 (10 weeks); the deload in week 31 makes 12, well inside the cap.
    expect(phaseEndFixes(20, 30, PhaseType::MuscleGain, [[20, 6], [26, 6]], ExperienceLevel::Intermediate))
        ->toEqual(new PhaseEndFixes(movedEndWeek: 32, mesocycleLength: null));
});

it('PLAN-027 "Build your next mesocycle" opens during the deload week', function () {
    $mesocycle = new PlannedMesocycle(startWeek: 4, weeks: 5);

    expect(MesocycleAlignment::buildsNextMesocycle(8, $mesocycle))->toBeTrue();
    foreach ([4, 5, 6, 7, 9] as $week) {
        expect(MesocycleAlignment::buildsNextMesocycle($week, $mesocycle))->toBeFalse("week {$week}");
    }
});
