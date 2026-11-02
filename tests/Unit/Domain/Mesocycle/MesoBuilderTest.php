<?php

use App\Domain\Defaults\TBL012TrainingDefaults\Column;
use App\Domain\Mesocycle\Exercise;
use App\Domain\Mesocycle\MesoBuilder;
use App\Domain\Mesocycle\MesoBuilder\SpecialisingInFatLoss;
use App\Domain\Mesocycle\MesoBuilder\TooManyEmphasizedInMuscleGain;
use App\Domain\Mesocycle\StimulusToFatigue;
use App\Domain\Model\ExerciseSlot;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Priority;
use App\Domain\Model\Session;

/**
 * Every muscle at one priority.
 *
 * @return array<string, Priority>
 */
function mesoAll(Priority $priority = Priority::Grow): array
{
    return array_combine(
        array_map(fn (MuscleGroup $muscle) => $muscle->value, MuscleGroup::cases()),
        array_fill(0, count(MuscleGroup::cases()), $priority),
    );
}

/**
 * The builder's sessions with ids s1, s2, ... in the order they are asked for.
 *
 * @param  array<string, Priority>  $priorities
 * @return list<Session>
 */
function mesoSessions(int $daysPerWeek, array $priorities, Column $column): array
{
    $next = 0;

    return MesoBuilder::sessions($daysPerWeek, $priorities, $column, function () use (&$next) {
        return 's'.++$next;
    });
}

/** @param  list<Session>  $sessions */
function mesoFrequency(MuscleGroup $muscle, array $sessions): int
{
    return count(mesoDays($muscle, $sessions));
}

/**
 * @param  list<Session>  $sessions
 * @return list<int>
 */
function mesoDays(MuscleGroup $muscle, array $sessions): array
{
    $days = [];
    foreach ($sessions as $index => $session) {
        foreach ($session->slots as $slot) {
            if ($slot->muscle === $muscle) {
                $days[] = $index;
                break;
            }
        }
    }

    return $days;
}

/**
 * @param  list<Session>  $sessions
 * @return list<ExerciseSlot>
 */
function mesoSlots(array $sessions): array
{
    return array_merge(...array_map(fn (Session $session) => $session->slots, $sessions));
}

function mesoSlotFor(Session $session, MuscleGroup $muscle): ?ExerciseSlot
{
    foreach ($session->slots as $slot) {
        if ($slot->muscle === $muscle) {
            return $slot;
        }
    }

    return null;
}

function mesoDeadlift(): Exercise
{
    return new Exercise('Deadlift', MuscleGroup::Hamstrings, StimulusToFatigue::Low, isCompound: true);
}

function mesoStiffLeg(): Exercise
{
    return new Exercise('Stiff-leg deadlift', MuscleGroup::Hamstrings, StimulusToFatigue::High, isCompound: true);
}

function mesoLegCurl(): Exercise
{
    return new Exercise('Leg curl', MuscleGroup::Hamstrings, StimulusToFatigue::High, isCompound: false);
}

function mesoGoodMorning(): Exercise
{
    return new Exercise('Good morning', MuscleGroup::Hamstrings, StimulusToFatigue::Medium, isCompound: true);
}

function mesoBench(): Exercise
{
    return new Exercise('Bench press', MuscleGroup::Chest, StimulusToFatigue::Medium, isCompound: true);
}

function mesoFly(): Exercise
{
    return new Exercise('Cable fly', MuscleGroup::Chest, StimulusToFatigue::High, isCompound: false);
}

// MESO-020

it('MESO-020 builds one session per day for the prioritised muscles', function () {
    $sessions = mesoSessions(4, ['chest' => Priority::Emphasize, 'back' => Priority::Grow, 'biceps' => Priority::Maintain], Column::MuscleGain);

    expect(array_map(fn (Session $s) => $s->name, $sessions))->toBe(['Day 1', 'Day 2', 'Day 3', 'Day 4']);
    $trained = array_values(array_unique(array_map(fn (ExerciseSlot $slot) => $slot->muscle->value, mesoSlots($sessions))));
    sort($trained);
    expect($trained)->toBe(['back', 'biceps', 'chest']);
});

it('MESO-020 default priorities follow the phase preset', function () {
    $values = fn (Column $column) => array_values(array_unique(array_map(
        fn (Priority $p) => $p->value, MesoBuilder::defaultPriorities($column),
    )));

    expect($values(Column::FatLoss))->toBe(['emphasize'])
        ->and($values(Column::MiniCut))->toBe(['emphasize'])
        ->and($values(Column::MaintenanceResensitization))->toBe(['maintain'])
        ->and($values(Column::MaintenanceBalanced))->toBe(['grow'])
        ->and($values(Column::MuscleGain))->toBe(['grow'])
        ->and(MesoBuilder::defaultPriorities(Column::FatLoss))->toHaveCount(count(MuscleGroup::cases()));
});

// MESO-021 / PH-010 / PH-011

it('MESO-021 suggests at most two Emphasize in Muscle gain only', function () {
    expect(MesoBuilder::emphasizeLimit(PhaseType::MuscleGain))->toBe(2)
        ->and(MesoBuilder::emphasizeLimit(PhaseType::FatLoss))->toBeNull()
        ->and(MesoBuilder::emphasizeLimit(PhaseType::Maintenance))->toBeNull();
});

it('MESO-021 PH-010 three Emphasize in Muscle gain warns', function () {
    $priorities = mesoAll();
    $priorities['chest'] = Priority::Emphasize;
    $priorities['back'] = Priority::Emphasize;
    expect(MesoBuilder::warnings($priorities, PhaseType::MuscleGain))->toBe([]);

    $priorities['quads'] = Priority::Emphasize;
    $warnings = MesoBuilder::warnings($priorities, PhaseType::MuscleGain);
    expect($warnings)->toEqual([new TooManyEmphasizedInMuscleGain(count: 3)])
        ->and($warnings[0]->message())->toBe('Emphasizing more than 2 muscles at once spreads your recovery thin');

    // A warning never blocks: the structure still builds and trains all three.
    $sessions = mesoSessions(4, $priorities, Column::MuscleGain);
    foreach ([MuscleGroup::Chest, MuscleGroup::Back, MuscleGroup::Quads] as $muscle) {
        expect(mesoFrequency($muscle, $sessions))->toBeGreaterThan(0);
    }
});

it('MESO-021 PH-011 specialising in Fat loss warns', function () {
    $priorities = mesoAll(Priority::Emphasize);
    expect(MesoBuilder::warnings($priorities, PhaseType::FatLoss))->toBe([]);

    $priorities['calves'] = Priority::Maintain;
    $priorities['biceps'] = Priority::Grow;
    $expected = [new SpecialisingInFatLoss(notEmphasized: [MuscleGroup::Biceps, MuscleGroup::Calves])];
    $warnings = MesoBuilder::warnings($priorities, PhaseType::FatLoss);
    expect($warnings)->toEqual($expected)
        ->and($warnings[0]->message())->toBe('Specialising during a cut works against recovery');
    // PLAN-009: Mini-cut counts as Fat loss.
    expect(MesoBuilder::warnings($priorities, PhaseType::MiniCut))->toEqual($expected)
        ->and(mesoSessions(3, $priorities, Column::FatLoss))->not->toBeEmpty();
});

it('MESO-021 PH-011 does not warn without any Emphasize', function () {
    expect(MesoBuilder::warnings(mesoAll(), PhaseType::FatLoss))->toBe([]);
});

it('MESO-021 Maintenance never warns', function () {
    expect(MesoBuilder::warnings(mesoAll(Priority::Emphasize), PhaseType::Maintenance))->toBe([]);
});

// MESO-022

it('MESO-022 structure is unpicked slots, one per muscle per day', function () {
    $sessions = mesoSessions(3, mesoAll(), Column::MaintenanceBalanced);

    expect($sessions)->toHaveCount(3);
    foreach ($sessions as $session) {
        foreach ($session->slots as $slot) {
            expect($slot->exerciseName)->toBe('')->and($slot->sets)->toBe(0);
        }
        $muscles = array_map(fn (ExerciseSlot $slot) => $slot->muscle->value, $session->slots);
        expect($muscles)->toHaveCount(count(array_unique($muscles)));
    }
});

it('MESO-022 gives every session and slot its own id from the caller', function () {
    $sessions = mesoSessions(2, ['chest' => Priority::Grow], Column::MuscleGain);
    $ids = [
        ...array_map(fn (Session $s) => $s->id, $sessions),
        ...array_map(fn (ExerciseSlot $slot) => $slot->id, mesoSlots($sessions)),
    ];

    expect($ids)->toHaveCount(4)->and(array_unique($ids))->toHaveCount(4);
});

it('MESO-022 spreads the load across days', function () {
    $counts = array_map(fn (Session $s) => count($s->slots), mesoSessions(4, mesoAll(), Column::MuscleGain));

    expect(max($counts) - min($counts))->toBeLessThanOrEqual(1);
});

it('MESO-022 a muscle trained twice is not on back-to-back days when days allow', function () {
    $sessions = mesoSessions(4, ['quads' => Priority::Grow], Column::MuscleGain);

    expect(mesoDays(MuscleGroup::Quads, $sessions))->toBe([0, 2]);
});

// MESO-023 / TBL-012

it('MESO-023 slower muscles twice, faster three times when days allow', function () {
    $sessions = mesoSessions(5, mesoAll(), Column::MuscleGain);

    foreach ([MuscleGroup::Back, MuscleGroup::Quads, MuscleGroup::Hamstrings, MuscleGroup::Glutes] as $muscle) {
        expect(mesoFrequency($muscle, $sessions))->toBe(2, $muscle->value);
    }
    foreach ([MuscleGroup::SideDelts, MuscleGroup::RearDelts, MuscleGroup::FrontDelts, MuscleGroup::Biceps, MuscleGroup::Triceps, MuscleGroup::Calves, MuscleGroup::Abs] as $muscle) {
        expect(mesoFrequency($muscle, $sessions))->toBe(3, $muscle->value);
    }
    // Not named either way: the phase minimum (2x).
    foreach ([MuscleGroup::Chest, MuscleGroup::Traps, MuscleGroup::Forearms] as $muscle) {
        expect(mesoFrequency($muscle, $sessions))->toBe(2, $muscle->value);
    }
});

it('MESO-023 frequency is capped by days per week', function () {
    $two = mesoSessions(2, mesoAll(), Column::FatLoss);
    foreach (MuscleGroup::cases() as $muscle) {
        expect(mesoFrequency($muscle, $two))->toBe(2, $muscle->value);
    }

    $one = mesoSessions(1, mesoAll(), Column::FatLoss);
    expect($one)->toHaveCount(1)
        ->and($one[0]->slots)->toHaveCount(count(MuscleGroup::cases()));
});

it('MESO-023 Fat loss trains every muscle at least twice', function () {
    foreach (range(2, 6) as $days) {
        $sessions = mesoSessions($days, MesoBuilder::defaultPriorities(Column::FatLoss), Column::FatLoss);
        foreach (MuscleGroup::cases() as $muscle) {
            expect(mesoFrequency($muscle, $sessions))->toBeGreaterThanOrEqual(2, "{$muscle->value} {$days} days");
        }
    }
});

it('MESO-023 TBL-012 resensitization minimum is once but recovery still sets two', function () {
    // TBL-012 lets resensitization go 1x, but MESO-023 still asks 2x for slower muscles.
    $sessions = mesoSessions(3, ['back' => Priority::Maintain, 'chest' => Priority::Maintain], Column::MaintenanceResensitization);

    expect(mesoFrequency(MuscleGroup::Back, $sessions))->toBe(2)
        ->and(mesoFrequency(MuscleGroup::Chest, $sessions))->toBe(1);
});

// MESO-030

it('MESO-030 priority bars', function () {
    expect(Priority::Emphasize->bars())->toBe(3)
        ->and(Priority::Grow->bars())->toBe(2)
        ->and(Priority::Maintain->bars())->toBe(1);
});

// MESO-013 / MESO-016

it('MESO-016 pick for me prefers high-rated exercises in Fat loss', function () {
    expect(MesoBuilder::pickForMe(MuscleGroup::Hamstrings, [mesoDeadlift(), mesoGoodMorning(), mesoStiffLeg()]))->toEqual(mesoStiffLeg());
});

it('MESO-013 pick for me takes the highest rating for the slot muscle', function () {
    expect(MesoBuilder::pickForMe(MuscleGroup::Hamstrings, [mesoDeadlift(), mesoGoodMorning()]))->toEqual(mesoGoodMorning())
        ->and(MesoBuilder::pickForMe(MuscleGroup::Chest, [mesoDeadlift(), mesoBench()]))->toEqual(mesoBench())
        ->and(MesoBuilder::pickForMe(MuscleGroup::Calves, [mesoDeadlift(), mesoBench()]))->toBeNull()
        // Ties: compound first, then list order.
        ->and(MesoBuilder::pickForMe(MuscleGroup::Hamstrings, [mesoLegCurl(), mesoStiffLeg()]))->toEqual(mesoStiffLeg());
});

it('MESO-013 pick for me fills every empty slot and keeps picked ones', function () {
    $sessions = mesoSessions(2, ['chest' => Priority::Grow, 'hamstrings' => Priority::Grow], Column::MuscleGain);
    $first = $sessions[0];
    $sessions[0] = new Session($first->id, $first->name, array_map(
        fn (ExerciseSlot $slot) => $slot->muscle === MuscleGroup::Chest
            ? new ExerciseSlot($slot->id, 'Bench press', $slot->muscle, $slot->sets)
            : $slot,
        $first->slots,
    ));

    $filled = MesoBuilder::pickForSessions($sessions, [mesoDeadlift(), mesoStiffLeg(), mesoFly()]);
    expect(array_map(fn (Session $s) => $s->id, $filled))->toBe(array_map(fn (Session $s) => $s->id, $sessions))
        ->and(mesoSlotFor($filled[0], MuscleGroup::Chest)?->exerciseName)->toBe('Bench press')
        ->and(mesoSlotFor($filled[1], MuscleGroup::Chest)?->exerciseName)->toBe('Cable fly')
        ->and(array_values(array_map(
            fn (ExerciseSlot $slot) => $slot->exerciseName,
            array_filter(mesoSlots($filled), fn (ExerciseSlot $slot) => $slot->muscle === MuscleGroup::Hamstrings),
        )))->toBe(['Stiff-leg deadlift', 'Stiff-leg deadlift']);

    // No exercise for a muscle: the slot stays empty.
    $none = MesoBuilder::pickForSessions($sessions, []);
    expect(mesoSlotFor($none[1], MuscleGroup::Chest)?->exerciseName)->toBe('');

    // A filled slot keeps its id and its set count.
    $planned = MesoBuilder::pickForSessions(
        [new Session('day-1', 'Day 1', [new ExerciseSlot('slot-1', '', MuscleGroup::Hamstrings, 3)])],
        [mesoStiffLeg()],
    );
    expect($planned[0]->slots[0])->toEqual(new ExerciseSlot('slot-1', 'Stiff-leg deadlift', MuscleGroup::Hamstrings, 3));
});

// MESO-046

it('MESO-046 order is priority, then rating, then compounds', function () {
    $curl = new Exercise('Curl', MuscleGroup::Biceps, StimulusToFatigue::High, isCompound: false);
    $squat = new Exercise('Squat', MuscleGroup::Quads, StimulusToFatigue::Medium, isCompound: true);
    $legPress = new Exercise('Leg press', MuscleGroup::Quads, StimulusToFatigue::Medium, isCompound: false);

    $ordered = MesoBuilder::order(
        [mesoFly(), $curl, $legPress, mesoDeadlift(), $squat, mesoBench()],
        ['chest' => Priority::Grow, 'biceps' => Priority::Emphasize, 'quads' => Priority::Grow, 'hamstrings' => Priority::Grow],
    );

    // Emphasize first; then within Grow, high before medium before low; compounds first on ties.
    expect(array_map(fn (Exercise $e) => $e->name, $ordered))
        ->toBe(['Curl', 'Cable fly', 'Squat', 'Bench press', 'Leg press', 'Deadlift']);
});

it('MESO-046 muscles without a priority go last', function () {
    $ordered = MesoBuilder::order([mesoFly(), mesoBench(), mesoStiffLeg()], ['chest' => Priority::Maintain]);

    expect(array_map(fn (Exercise $e) => $e->name, $ordered))->toBe(['Cable fly', 'Bench press', 'Stiff-leg deadlift']);
});

it('MESO-046 generated slots start in priority order', function () {
    $sessions = mesoSessions(1, ['chest' => Priority::Maintain, 'back' => Priority::Grow, 'calves' => Priority::Emphasize], Column::MuscleGain);

    expect(array_map(fn (ExerciseSlot $slot) => $slot->muscle, $sessions[0]->slots))
        ->toBe([MuscleGroup::Calves, MuscleGroup::Back, MuscleGroup::Chest]);
});

it('MESO-046 order slots uses the exercise list and unpicked slots rank lowest', function () {
    $session = new Session('day-1', 'Day 1', [
        new ExerciseSlot('a', '', MuscleGroup::Calves, 0),
        new ExerciseSlot('b', 'Bench press', MuscleGroup::Chest, 3),
        new ExerciseSlot('c', 'Cable fly', MuscleGroup::Chest, 2),
        new ExerciseSlot('d', 'Stiff-leg deadlift', MuscleGroup::Hamstrings, 2),
    ]);

    $ordered = MesoBuilder::orderSession(
        $session,
        ['chest' => Priority::Grow, 'hamstrings' => Priority::Emphasize, 'calves' => Priority::Maintain],
        [mesoBench(), mesoFly(), mesoStiffLeg()],
    );

    expect($ordered->id)->toBe($session->id)
        ->and(array_map(fn (ExerciseSlot $slot) => $slot->exerciseName, $ordered->slots))
        ->toBe(['Stiff-leg deadlift', 'Cable fly', 'Bench press', '']);
});

it('MESO-046 order slots ranks an unpicked or unknown slot below every rated one of its priority', function () {
    // 'Deadlift' is in the library, but as a Hamstrings exercise, so on a Chest slot it is unknown.
    $session = new Session('day-1', 'Day 1', [
        new ExerciseSlot('a', '', MuscleGroup::Chest, 0),
        new ExerciseSlot('b', 'Deadlift', MuscleGroup::Chest, 0),
        new ExerciseSlot('c', 'Bench press', MuscleGroup::Chest, 0),
    ]);

    $ordered = MesoBuilder::orderSession($session, ['chest' => Priority::Grow], [mesoDeadlift(), mesoBench()]);

    // The rated slot first; the two unknown ones after it, in list order.
    expect(array_map(fn (ExerciseSlot $slot) => $slot->id, $ordered->slots))->toBe(['c', 'a', 'b']);
});
