<?php

use App\Actions\Mesocycles\AddMesocycleDay;
use App\Actions\Mesocycles\SetMusclePriority;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\Priority;
use App\Domain\Model\Session;
use App\Models\Mesocycle;
use App\Support\Mesocycles\TrainSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\TrainSeed;

// A muscle's priority on a build (MESO-020) and on a running mesocycle (MESO-032, MESO-034). Meso 2 runs 5 weeks from
// Monday 7 September under muscle gain: Upper A is bench (chest), Lower A is squat (quads) and an empty calves slot.
uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/** @param  array<string, mixed>  $attributes */
function priorityMeso(array $attributes = []): Mesocycle
{
    Carbon::setTestNow('2026-09-22 10:00:00');
    $user = TrainSeed::user();
    $gain = TrainSeed::phase($user, 'muscleGain', '2026-08-31');

    return TrainSeed::mesocycle($user, ['phase_id' => $gain->id, ...$attributes]);
}

function prioritySet(Mesocycle $mesocycle, MuscleGroup $muscle, ?Priority $priority): Mesocycle
{
    app(SetMusclePriority::class)($mesocycle->user, $mesocycle->id, $muscle, $priority);

    return $mesocycle->fresh();
}

it('MESO-020 on a build the change starts from the phase\'s preset and keeps the rest of it', function () {
    $draft = priorityMeso(['is_draft' => true, 'muscle_priorities' => []]);

    $priorities = prioritySet($draft, MuscleGroup::Biceps, Priority::Emphasize)->muscle_priorities;

    expect($priorities['biceps'])->toBe('emphasize')
        // Muscle gain's preset is Grow for every muscle.
        ->and($priorities['chest'])->toBe('grow')
        ->and(count($priorities))->toBe(14);
});

it('MESO-034 a build cannot set a muscle Off; that is for a running mesocycle', function () {
    $draft = priorityMeso(['is_draft' => true, 'muscle_priorities' => []]);

    expect(fn () => prioritySet($draft, MuscleGroup::Biceps, null))
        ->toThrow(DomainException::class, 'A muscle can be set Off only once the mesocycle is running.');
});

it('MESO-032 a running mesocycle takes the new priority and keeps the others', function () {
    $mesocycle = priorityMeso();

    expect(prioritySet($mesocycle, MuscleGroup::Quads, Priority::Emphasize)->muscle_priorities)
        ->toBe(['chest' => 'emphasize', 'quads' => 'emphasize', 'forearms' => null]);
});

it('MESO-034 Off is stored as no priority, and a priority brings the muscle back', function () {
    $mesocycle = priorityMeso();

    expect(prioritySet($mesocycle, MuscleGroup::Quads, null)->muscle_priorities)->toBe(['chest' => 'emphasize', 'quads' => null, 'forearms' => null])
        ->and(prioritySet($mesocycle, MuscleGroup::Quads, Priority::Maintain)->muscle_priorities['quads'])->toBe('maintain');
});

it('MESO-034 from then on an Off muscle\'s slots are left out of the sessions; a session left empty plans nothing', function () {
    $mesocycle = prioritySet(priorityMeso(), MuscleGroup::Chest, null);
    $mesocycle = prioritySet($mesocycle, MuscleGroup::Calves, null);

    $sessions = TrainSnapshot::load($mesocycle->user, $mesocycle->id)->domain($mesocycle)->sessionsInWeek(2);

    expect(array_map(fn (Session $s) => count($s->slots), $sessions))->toBe([0, 1])
        ->and($sessions[1]->slots[0]->muscle)->toBe(MuscleGroup::Quads)
        // MESO-034, MESO-030: the slots stay stored, so setting a priority again brings them back.
        ->and(count($mesocycle->dayRows()[0]['slots']))->toBe(1);
});

it('MESO-032 choosing the priority a muscle already has changes nothing', function () {
    $mesocycle = priorityMeso();
    $before = $mesocycle->fresh()->updated_at;
    $this->travel(5)->minutes();

    $after = prioritySet($mesocycle, MuscleGroup::Chest, Priority::Emphasize);

    expect($after->updated_at)->toEqual($before)
        ->and($after->muscle_priorities)->toBe(['chest' => 'emphasize', 'quads' => 'grow', 'forearms' => null]);
});

it('MESO-034 setting a muscle Off keeps the weeks that ran and this week\'s done sessions as they were', function () {
    $mesocycle = priorityMeso();
    // Week 3 (from Monday 21 September): Upper A is done, Lower A is still to come.
    TrainSeed::trained($mesocycle, 3, 1, '2026-09-21');

    $mesocycle = prioritySet($mesocycle, MuscleGroup::Chest, null);
    $mesocycle = prioritySet($mesocycle, MuscleGroup::Quads, null);
    $domain = TrainSnapshot::load($mesocycle->user, $mesocycle->id)->domain($mesocycle);
    $muscles = fn (int $week) => array_map(
        fn (Session $s) => array_map(fn ($slot) => $slot->muscle->value, $s->slots),
        $domain->sessionsInWeek($week),
    );

    expect($muscles(0))->toBe([['chest'], ['quads', 'calves']])
        ->and($muscles(1))->toBe([['chest'], ['quads', 'calves']])
        ->and($muscles(2))->toBe([['chest'], ['calves']])
        ->and($muscles(3))->toBe([[], ['calves']])
        ->and($muscles(4))->toBe([[], ['calves']]);
});

it('MESO-032 MESO-034 a mesocycle that has ended takes no priority change', function (?Priority $priority) {
    $mesocycle = priorityMeso();
    Carbon::setTestNow('2026-11-02 10:00:00');

    expect(fn () => prioritySet($mesocycle, MuscleGroup::Quads, $priority))->toThrow(DomainException::class, 'This mesocycle has ended.');
})->with([Priority::Maintain, null]);

it('MESO-034 Off on a mesocycle not started yet applies to all of it', function () {
    $mesocycle = priorityMeso(['start_date' => '2026-10-05']);

    $mesocycle = prioritySet($mesocycle, MuscleGroup::Chest, null);

    expect($mesocycle->earlier_weeks ?? [])->toBe([])
        ->and(TrainSnapshot::load($mesocycle->user, $mesocycle->id)->domain($mesocycle)->sessionsInWeek(0)[0]->slots)->toBe([]);
});

/** @return list<list<string>> each session's muscles in a mesocycle week (0 is the first) */
function priorityWeek(Mesocycle $mesocycle, int $week): array
{
    $mesocycle = $mesocycle->fresh();
    $domain = TrainSnapshot::load($mesocycle->user, $mesocycle->id)->domain($mesocycle);

    return array_map(fn (Session $s) => array_map(fn ($slot) => $slot->muscle->value, $s->slots), $domain->sessionsInWeek($week));
}

it('MESO-032 MESO-034 bringing a muscle back keeps the weeks it was Off as they ran, and this week has it again', function () {
    $mesocycle = priorityMeso();
    Carbon::setTestNow('2026-09-15 10:00:00');
    prioritySet($mesocycle, MuscleGroup::Chest, null);
    Carbon::setTestNow('2026-09-29 10:00:00');
    prioritySet($mesocycle, MuscleGroup::Chest, Priority::Grow);

    expect(priorityWeek($mesocycle, 0))->toBe([['chest'], ['quads', 'calves']])
        ->and(priorityWeek($mesocycle, 1))->toBe([[], ['quads', 'calves']])
        ->and(priorityWeek($mesocycle, 2))->toBe([[], ['quads', 'calves']])
        ->and(priorityWeek($mesocycle, 3))->toBe([['chest'], ['quads', 'calves']]);
});

it('MESO-034 a muscle set Off before the start stays out of the weeks stored later', function () {
    $mesocycle = priorityMeso();
    Carbon::setTestNow('2026-09-01 10:00:00');
    prioritySet($mesocycle, MuscleGroup::Calves, null);
    Carbon::setTestNow('2026-09-22 10:00:00');
    prioritySet($mesocycle, MuscleGroup::Chest, null);

    expect(priorityWeek($mesocycle, 0))->toBe([['chest'], ['quads']])
        ->and(priorityWeek($mesocycle, 2))->toBe([[], ['quads']]);
});

it('MESO-034 MESO-045 a day added after a muscle went Off stores the weeks that ran without it, and joins this week', function () {
    $mesocycle = priorityMeso();
    Carbon::setTestNow('2026-09-01 10:00:00');
    prioritySet($mesocycle, MuscleGroup::Calves, null);
    Carbon::setTestNow('2026-09-22 10:00:00');
    prioritySet($mesocycle, MuscleGroup::Chest, null);
    app(AddMesocycleDay::class)($mesocycle->user, $mesocycle->id, '01JE00000000000000000000DA');

    expect(priorityWeek($mesocycle, 0))->toBe([['chest'], ['quads']])
        ->and(priorityWeek($mesocycle, 2))->toBe([[], ['quads'], []])
        // The rotation keeps the Off muscles' slots for when they come back.
        ->and(array_column($mesocycle->fresh()->dayRows()[1]['slots'], 'muscle_group'))->toBe(['quads', 'calves']);
});

it('MESO-032 MESO-034 bringing a muscle back in the week it went Off keeps the sessions done without it', function () {
    $mesocycle = priorityMeso();
    prioritySet($mesocycle, MuscleGroup::Chest, null);
    // Monday's Upper A runs without chest; on Tuesday chest is back.
    TrainSeed::trained($mesocycle->fresh(), 3, 1, '2026-09-21');
    Carbon::setTestNow('2026-09-23 10:00:00');
    prioritySet($mesocycle, MuscleGroup::Chest, Priority::Grow);

    expect(priorityWeek($mesocycle, 2))->toBe([[], ['quads', 'calves']])
        ->and(priorityWeek($mesocycle, 3))->toBe([['chest'], ['quads', 'calves']]);
});

it('MESO-034 MESO-045 a day added with a muscle Off since before the start stores the weeks that ran without it', function () {
    $mesocycle = priorityMeso();
    Carbon::setTestNow('2026-09-01 10:00:00');
    prioritySet($mesocycle, MuscleGroup::Calves, null);
    Carbon::setTestNow('2026-09-22 10:00:00');
    app(AddMesocycleDay::class)($mesocycle->user, $mesocycle->id, '01JE00000000000000000000DA');

    expect(priorityWeek($mesocycle, 0))->toBe([['chest'], ['quads']])
        ->and(priorityWeek($mesocycle, 1))->toBe([['chest'], ['quads']])
        ->and(priorityWeek($mesocycle, 2))->toBe([['chest'], ['quads'], []])
        ->and(array_column($mesocycle->fresh()->dayRows()[1]['slots'], 'muscle_group'))->toBe(['quads', 'calves']);
});
