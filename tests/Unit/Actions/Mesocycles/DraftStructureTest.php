<?php

use App\Actions\Mesocycles\FillMesocycleSlots;
use App\Actions\Mesocycles\GenerateMesocycleStructure;
use App\Domain\Model\MuscleGroup;
use App\Enums\SfrRating;
use App\Models\Exercise;
use App\Models\Mesocycle;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\TrainSeed;

// "Continue" on the builder or scratch step (MESO-022, MESO-023) and "Pick for me" (MESO-013, MESO-016), on a draft
// saved in the database. The user trains Monday and Thursday; plan weeks start on Monday.
uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/** @param  array<string, mixed>  $attributes */
function structureDraft(string $phase = 'muscleGain', array $attributes = []): Mesocycle
{
    Carbon::setTestNow('2026-10-07 10:00:00');
    $user = TrainSeed::user();
    TrainSeed::phase($user, $phase, '2026-08-31');

    return TrainSeed::mesocycle($user, array_replace([
        'is_draft' => true, 'route' => 'builder', 'name' => null, 'weeks' => null, 'start_date' => null,
        'days' => [], 'muscle_priorities' => [],
    ], $attributes));
}

/** @return list<string> each slot's muscle, day by day */
function structureMuscles(Mesocycle $draft, int $day): array
{
    return array_column($draft->fresh()->dayRows()[$day]['slots'], 'muscle_group');
}

/** @return array<string, int> how many days train each muscle */
function structureFrequency(Mesocycle $draft): array
{
    $count = [];
    foreach ($draft->fresh()->dayRows() as $day) {
        foreach (array_unique(array_column($day['slots'], 'muscle_group')) as $muscle) {
            $count[$muscle] = ($count[$muscle] ?? 0) + 1;
        }
    }

    return $count;
}

it('MESO-022 the builder lays out its days per week, every slot at 2 sets with no exercise yet', function () {
    $draft = structureDraft(attributes: ['days_per_week' => 3]);

    app(GenerateMesocycleStructure::class)($draft->user, $draft->id);
    $days = $draft->fresh()->dayRows();
    $slots = array_merge(...array_column($days, 'slots'));

    expect(count($days))->toBe(3)
        ->and(array_unique(array_column($slots, 'sets')))->toBe([2])
        ->and(array_unique(array_column($slots, 'exercise_id')))->toBe([null])
        ->and(array_unique(array_map(fn (string $id) => strlen($id), [...array_column($days, 'id'), ...array_column($slots, 'id')])))->toBe([26])
        // MESO-005: three days for two training days float.
        ->and(array_column($days, 'label'))->toBe(['Day 1', 'Day 2', 'Day 3'])
        // MESO-033: every muscle is trained.
        ->and(count(structureFrequency($draft)))->toBe(14);
});

it('MESO-020 MESO-005 days per week default to the training days, which then label them', function () {
    $draft = structureDraft();

    app(GenerateMesocycleStructure::class)($draft->user, $draft->id);

    expect(array_column($draft->fresh()->dayRows(), 'label'))->toBe(['Monday', 'Thursday']);
});

it('MESO-023 slower-recovering muscles go twice a week and faster ones up to three times', function () {
    $draft = structureDraft(attributes: ['days_per_week' => 6]);

    app(GenerateMesocycleStructure::class)($draft->user, $draft->id);
    $frequency = structureFrequency($draft);

    expect([$frequency['back'], $frequency['quads'], $frequency['hamstrings'], $frequency['glutes']])->toBe([2, 2, 2, 2])
        ->and([$frequency['sideDelts'], $frequency['biceps'], $frequency['calves'], $frequency['abs']])->toBe([3, 3, 3, 3]);
});

it('MESO-023 in fat loss every muscle is trained at least twice a week', function () {
    $draft = structureDraft('fatLoss', ['days_per_week' => 4]);

    app(GenerateMesocycleStructure::class)($draft->user, $draft->id);

    expect(min(structureFrequency($draft)))->toBeGreaterThanOrEqual(2);
});

it('MESO-020 MESO-046 the builder follows the stored priorities, Emphasize first on each day', function () {
    $draft = structureDraft(attributes: ['days_per_week' => 2, 'muscle_priorities' => ['biceps' => 'emphasize', 'chest' => 'maintain', 'forearms' => null]]);

    app(GenerateMesocycleStructure::class)($draft->user, $draft->id);

    expect(structureMuscles($draft, 0)[0])->toBe('biceps')
        ->and(structureMuscles($draft, 1)[0])->toBe('biceps')
        // A muscle left out reads as Maintain and is trained; an Off one (MESO-034) is not.
        ->and(array_keys(structureFrequency($draft)))->toContain('quads')
        ->and(array_keys(structureFrequency($draft)))->not->toContain('forearms');
});

it('MESO-002 build from scratch lays out empty days', function () {
    $draft = structureDraft(attributes: ['route' => 'scratch', 'days_per_week' => 4]);

    app(GenerateMesocycleStructure::class)($draft->user, $draft->id);
    $days = $draft->fresh()->dayRows();

    expect(array_column($days, 'label'))->toBe(['Day 1', 'Day 2', 'Day 3', 'Day 4'])
        ->and(array_merge(...array_column($days, 'slots')))->toBe([]);
});

it('MESO-022 a template or a copy already has its days, so there is nothing to generate', function (string $route) {
    $draft = structureDraft(attributes: ['route' => $route, 'days' => [['id' => 'd1', 'label' => null, 'weekday' => 1, 'slots' => []]]]);

    expect(fn () => app(GenerateMesocycleStructure::class)($draft->user, $draft->id))
        ->toThrow(DomainException::class, 'This build already has its days.')
        // MySQL keeps JSON keys in its own order, so the days are compared as values.
        ->and($draft->fresh()->days)->toEqual([['id' => 'd1', 'label' => null, 'weekday' => 1, 'slots' => []]]);
})->with(['template', 'copy_previous']);

function fillDraft(): Mesocycle
{
    $draft = structureDraft();
    $chest = TrainSeed::exercise('Bench Press', MuscleGroup::Chest);
    $chest->update(['sfr_rating' => SfrRating::Low]);
    $draft->update(['days' => [
        ['id' => 'd1', 'label' => null, 'weekday' => 1, 'slots' => [
            ['id' => 's1', 'exercise_id' => $chest->id, 'muscle_group' => 'chest', 'sets' => 3],
            ['id' => 's2', 'exercise_id' => null, 'muscle_group' => 'chest', 'sets' => 2],
            ['id' => 's3', 'exercise_id' => null, 'muscle_group' => 'back', 'sets' => 2],
            ['id' => 's4', 'exercise_id' => null, 'muscle_group' => 'forearms', 'sets' => 2],
        ]],
    ]]);

    return $draft;
}

it('MESO-013 MESO-016 pick for me fills every empty slot with the best-rated exercise and keeps the picked ones', function () {
    $draft = fillDraft();
    Exercise::factory()->create(['name' => 'Cable Fly', 'muscle_group' => 'chest', 'sfr_rating' => SfrRating::High]);
    Exercise::factory()->create(['name' => 'Conventional Deadlift', 'muscle_group' => 'back', 'sfr_rating' => SfrRating::Low, 'is_compound' => true]);
    $row = Exercise::factory()->create(['name' => 'Chest-Supported Row', 'muscle_group' => 'back', 'sfr_rating' => SfrRating::High, 'is_compound' => true]);
    // Another user's custom exercise is never picked.
    Exercise::factory()->custom()->create(['name' => 'Their Row', 'muscle_group' => 'back', 'sfr_rating' => SfrRating::High, 'is_compound' => true]);

    $filled = app(FillMesocycleSlots::class)($draft->user, $draft->id);
    $slots = $draft->fresh()->dayRows()[0]['slots'];

    expect($filled)->toBe(2)
        ->and($slots[0]['exercise_id'])->toBe(Exercise::query()->where('name', 'Bench Press')->value('id'))
        ->and($slots[1]['exercise_id'])->toBe(Exercise::query()->where('name', 'Cable Fly')->value('id'))
        ->and($slots[2]['exercise_id'])->toBe($row->id)
        // MESO-013: no forearm exercise in the library leaves the slot empty.
        ->and($slots[3]['exercise_id'])->toBeNull();
});

it('MESO-013 pick for me with every slot picked changes nothing', function () {
    $draft = fillDraft();
    $bench = TrainSeed::exercise('Bench Press', MuscleGroup::Chest);
    $picked = [['id' => 'd1', 'label' => null, 'weekday' => 1, 'slots' => [['id' => 's1', 'exercise_id' => $bench->id, 'muscle_group' => 'chest', 'sets' => 3]]]];
    $draft->update(['days' => $picked]);
    $before = $draft->fresh()->updated_at;
    $this->travel(5)->minutes();

    expect(app(FillMesocycleSlots::class)($draft->user, $draft->id))->toBe(0)
        ->and($draft->fresh()->days)->toEqual($picked)
        ->and($draft->fresh()->updated_at)->toEqual($before);
});

it('MESO-005 pick for me changes only the exercises; the days keep the labels they had', function () {
    $draft = fillDraft();

    app(FillMesocycleSlots::class)($draft->user, $draft->id);

    expect($draft->fresh()->days[0]['label'])->toBeNull()
        ->and($draft->fresh()->days[0]['weekday'])->toBe(1);
});

it('MESO-013 pick for me is for a build, not a saved mesocycle', function () {
    Carbon::setTestNow('2026-10-07 10:00:00');
    $saved = TrainSeed::mesocycle(TrainSeed::user());

    expect(fn () => app(FillMesocycleSlots::class)($saved->user, $saved->id))
        ->toThrow(ModelNotFoundException::class);
});

it('MESO-013 MESO-022 an unknown draft is not found', function () {
    Carbon::setTestNow('2026-10-07 10:00:00');
    $user = User::factory()->create();

    expect(fn () => app(GenerateMesocycleStructure::class)($user, '01JE0000000000000000000000'))
        ->toThrow(ModelNotFoundException::class);
});

it('MESO-020 with no days per week picked, Continue lays out as many days as the build shows', function () {
    $day = fn (string $id) => ['id' => $id, 'label' => null, 'weekday' => null, 'slots' => []];
    $draft = structureDraft(attributes: ['days' => [$day('a'), $day('b'), $day('c')]]);

    app(GenerateMesocycleStructure::class)($draft->user, $draft->id);

    expect(count($draft->fresh()->dayRows()))->toBe(3);
});
