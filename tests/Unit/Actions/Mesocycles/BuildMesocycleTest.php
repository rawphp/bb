<?php

use App\Actions\Mesocycles\BuildMesocycle;
use App\Capabilities\Mesocycles\Data\MesocycleBuildDay;
use App\Capabilities\Mesocycles\Data\MesocycleBuildInput;
use App\Capabilities\Mesocycles\Data\MesocycleBuildSlot;
use App\Domain\Model\MuscleGroup;
use App\Enums\MesocycleRoute;
use App\Models\Exercise;
use App\Models\Mesocycle;
use App\Models\Template;
use App\Models\TemplateSlot;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\TrainSeed;

// The action's job is the draft it saves, so it runs on the database. Plan weeks start on Monday from 31 August;
// the user trains Monday and Thursday and is intermediate. Muscle gain covers everything from 31 August.
uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

const BUILD = '01JE0000000000000000000000';

function buildWorld(bool $phase = true): User
{
    Carbon::setTestNow('2026-10-07 10:00:00');
    $user = TrainSeed::user();
    if ($phase) {
        TrainSeed::phase($user, 'muscleGain', '2026-08-31');
    }

    return $user;
}

/** @param  array<string, mixed>  $input */
function build(User $user, array $input = []): Mesocycle
{
    $id = $input['mesocycle_id'] ?? BUILD;
    app(BuildMesocycle::class)($user, Mesocycle::query()->find($id), MesocycleBuildInput::from(['mesocycle_id' => $id, ...$input]));

    return Mesocycle::query()->findOrFail($id);
}

function buildTemplate(?array $priorities = null): Template
{
    $template = Template::factory()->days(2)->create([
        'name' => 'Upper Lower Basics',
        'day_labels' => ['Upper', 'Lower'],
        'priorities' => $priorities ?? ['chest' => 'emphasize', 'quads' => 'grow'],
    ]);
    $bench = TrainSeed::exercise('Bench Press', MuscleGroup::Chest);
    $squat = TrainSeed::exercise('Back Squat', MuscleGroup::Quads);
    TemplateSlot::factory()->for($template)->create(['day' => 1, 'position' => 1, 'muscle_group' => 'chest', 'exercise_id' => $bench->id, 'sets' => 4]);
    TemplateSlot::factory()->for($template)->create(['day' => 2, 'position' => 1, 'muscle_group' => 'quads', 'exercise_id' => $squat->id, 'sets' => 3]);

    return $template;
}

/** @return list<array<string, mixed>> */
function buildDays(?int $benchId = null): array
{
    return [
        ['day_id' => '01JE0000000000000000000D01', 'slots' => [
            ['slot_id' => '01JE0000000000000000000S01', 'muscle' => 'chest', 'exercise_id' => $benchId === null ? null : (string) $benchId, 'sets' => 3],
            ['slot_id' => '01JE0000000000000000000S02', 'muscle' => 'biceps', 'exercise_id' => null, 'sets' => 2],
        ]],
        ['day_id' => '01JE0000000000000000000D02', 'slots' => []],
    ];
}

it('MESO-007 a new id starts a draft on its route and deletes the other unfinished build', function () {
    $user = buildWorld();
    $old = TrainSeed::mesocycle($user, ['is_draft' => true]);
    $saved = TrainSeed::mesocycle($user);
    $theirs = TrainSeed::mesocycle(User::factory()->create(), ['is_draft' => true]);

    $replaced = app(BuildMesocycle::class)($user, null, MesocycleBuildInput::from(['mesocycle_id' => BUILD, 'route' => 'builder']));
    $draft = Mesocycle::query()->findOrFail(BUILD);

    expect($replaced)->toBe([$old->id])
        ->and($old->fresh()->trashed())->toBeTrue()
        ->and($saved->fresh()->trashed())->toBeFalse()
        // Only the user's own builds go.
        ->and($theirs->fresh()->trashed())->toBeFalse()
        ->and($draft->is_draft)->toBeTrue()
        ->and($draft->route)->toBe(MesocycleRoute::Builder)
        ->and($draft->user_id)->toBe($user->id)
        // MESO-003: until the user changes one, the priorities follow the phase of the start.
        ->and($draft->muscle_priorities)->toBe([]);
});

it('MESO-007 the same id updates the draft and a field left out keeps its value', function () {
    $user = buildWorld();
    build($user, ['route' => 'scratch', 'name' => '  Spring block ', 'weeks' => 6, 'days_per_week' => 4]);
    $draft = build($user, ['start_date' => '2026-10-15']);

    expect($draft->name)->toBe('Spring block')
        ->and($draft->weeks)->toBe(6)
        ->and($draft->days_per_week)->toBe(4)
        ->and($draft->route)->toBe(MesocycleRoute::Scratch)
        // MESO-004: a start snaps to the first day of its plan week, and takes the phase covering it.
        ->and($draft->start_date->format('Y-m-d'))->toBe('2026-10-12')
        ->and($draft->phase_id)->not->toBeNull()
        ->and(build($user, ['name' => ''])->name)->toBeNull();
});

it('MESO-012 MESO-003 a template fills the days with its exercises and brings its name and priorities', function () {
    $user = buildWorld();
    $template = buildTemplate();

    $draft = build($user, ['template_id' => (string) $template->id]);
    $days = $draft->dayRows();

    expect($draft->route)->toBe(MesocycleRoute::Template)
        ->and($draft->name)->toBe('Upper Lower Basics')
        ->and($draft->muscle_priorities)->toBe(['chest' => 'emphasize', 'quads' => 'grow'])
        ->and(count($days))->toBe(2)
        ->and(array_column($days[0]['slots'], 'muscle_group'))->toBe(['chest'])
        ->and(array_column($days[0]['slots'], 'sets'))->toBe([4])
        ->and($days[0]['slots'][0]['exercise_id'])->toBe(Exercise::query()->where('name', 'Bench Press')->value('id'))
        ->and($days[1]['slots'][0]['exercise_id'])->toBe(Exercise::query()->where('name', 'Back Squat')->value('id'))
        // MESO-005: two days for two training days take Monday and Thursday.
        ->and(array_column($days, 'weekday'))->toBe([1, 4])
        ->and(array_column($days, 'label'))->toBe(['Monday', 'Thursday']);
});

it('MESO-003 a template without priorities leaves them to the phase', function () {
    $user = buildWorld();

    expect(build($user, ['template_id' => (string) buildTemplate([])->id])->muscle_priorities)->toBe([]);
});

it('MESO-012 a template that does not exist is not found', function () {
    $user = buildWorld();

    build($user, ['template_id' => '999999']);
})->throws(ModelNotFoundException::class);

it('MESO-014 days replace every day and slot, each keeping the ids the client gave', function () {
    $user = buildWorld();
    build($user, ['template_id' => (string) buildTemplate()->id]);
    $bench = TrainSeed::exercise('Bench Press', MuscleGroup::Chest);

    $days = build($user, ['days' => buildDays($bench->id), 'label_style' => 'floating'])->dayRows();

    expect(array_column($days, 'id'))->toBe(['01JE0000000000000000000D01', '01JE0000000000000000000D02'])
        ->and(array_column($days[0]['slots'], 'id'))->toBe(['01JE0000000000000000000S01', '01JE0000000000000000000S02'])
        ->and(array_column($days[0]['slots'], 'exercise_id'))->toBe([$bench->id, null])
        ->and(array_column($days[0]['slots'], 'sets'))->toBe([3, 2])
        ->and($days[1]['slots'])->toBe([])
        // MESO-005: a floating split is numbered.
        ->and(array_column($days, 'label'))->toBe(['Day 1', 'Day 2'])
        ->and(array_column($days, 'weekday'))->toBe([null, null]);
});

it('MESO-014 MESO-061 a slot keeps the load a copy carried while its exercise stays', function () {
    $user = buildWorld();
    $bench = TrainSeed::exercise('Bench Press', MuscleGroup::Chest);
    $carried = ['weight_kg' => 80.0, 'reps' => 10];
    TrainSeed::mesocycle($user, ['id' => BUILD, 'is_draft' => true, 'route' => 'copy_previous', 'days' => [
        ['id' => '01JE0000000000000000000D01', 'label' => null, 'weekday' => 1, 'slots' => [
            ['id' => '01JE0000000000000000000S01', 'exercise_id' => $bench->id, 'muscle_group' => 'chest', 'sets' => 3, 'carried' => $carried],
        ]],
    ]]);
    $other = TrainSeed::exercise('Incline Press', MuscleGroup::Chest);

    $kept = build($user, ['days' => buildDays($bench->id)])->dayRows();
    $swapped = build($user, ['days' => buildDays($other->id)])->dayRows();

    expect($kept[0]['slots'][0]['carried'])->toBe($carried)
        ->and($kept[0]['slots'][1]['carried'])->toBeNull()
        ->and($swapped[0]['slots'][0]['carried'])->toBeNull();
});

it('MESO-013 refuses an exercise that does not train the slot\'s muscle', function () {
    $user = buildWorld();
    $squat = TrainSeed::exercise('Back Squat', MuscleGroup::Quads);

    expect(fn () => build($user, ['days' => buildDays($squat->id)]))->toThrow(DomainException::class, 'Back Squat does not train Chest.');
});

it('MESO-013 another user\'s custom exercise is not found', function () {
    $user = buildWorld();
    $theirs = Exercise::factory()->custom()->create(['muscle_group' => 'chest']);

    expect(fn () => build($user, ['days' => buildDays($theirs->id)]))->toThrow(ModelNotFoundException::class);
});

it('MESO-004 MESO-020 refuses a length outside the experience\'s range and days per week outside 2 to 6', function (array $input, string $message) {
    $user = buildWorld();

    expect(fn () => build($user, $input))->toThrow(DomainException::class, $message);
})->with([
    // TBL-020: an intermediate lifter runs 5 to 8 weeks.
    'too short' => [['weeks' => 4], 'A mesocycle runs 5 to 8 weeks, the deload included.'],
    'too long' => [['weeks' => 9], 'A mesocycle runs 5 to 8 weeks, the deload included.'],
    'one day' => [['days_per_week' => 1], 'The Meso Builder plans 2 to 6 days a week.'],
    'seven days' => [['days_per_week' => 7], 'The Meso Builder plans 2 to 6 days a week.'],
]);

it('MESO-003 MESO-004 MESO-007 finishing saves the same row with the phase, length, start and priorities it shows', function () {
    $user = buildWorld();
    build($user, ['template_id' => (string) buildTemplate([])->id]);

    $saved = build($user, ['finish' => true]);

    expect($saved->is_draft)->toBeFalse()
        ->and($saved->isSaved())->toBeTrue()
        ->and($saved->weeks)->toBe(5)
        ->and($saved->start_date->format('Y-m-d'))->toBe('2026-10-12')
        ->and($saved->phase_id)->not->toBeNull()
        // Muscle gain's preset: every muscle at Grow.
        ->and(array_unique(array_values($saved->muscle_priorities)))->toBe(['grow'])
        ->and(count($saved->muscle_priorities))->toBe(14);
});

it('MESO-003 MESO-004 a build cannot finish without a name, a day, or a phase on its start', function (bool $phase, array $first, string $message) {
    $user = buildWorld($phase);
    build($user, $first);

    expect(fn () => build($user, ['finish' => true]))->toThrow(DomainException::class, $message);
})->with([
    'no name' => [true, ['route' => 'scratch', 'days' => [['day_id' => '01JE0000000000000000000D01', 'slots' => []]]], 'Give the mesocycle a name first.'],
    'no days' => [true, ['route' => 'scratch', 'name' => 'Block'], 'Add a day first.'],
    'no phase' => [false, ['route' => 'scratch', 'name' => 'Block', 'days' => [['day_id' => '01JE0000000000000000000D01', 'slots' => []]]], 'No phase covers this date. Pick a start inside your plan.'],
]);

it('MESO-007 a saved mesocycle is not built over', function () {
    $user = buildWorld();
    $saved = TrainSeed::mesocycle($user);

    expect(fn () => build($user, ['mesocycle_id' => $saved->id, 'name' => 'Renamed']))
        ->toThrow(DomainException::class, 'That mesocycle is already saved. Start a new build.');
});

it('MESO-014 a slot\'s sets stay between 1 and 20', function () {
    expect(MesocycleBuildSlot::jsonSchema()['properties']['sets'])->toMatchArray(['minimum' => 1, 'maximum' => 20])
        ->and(MesocycleBuildDay::jsonSchema()['required'])->toContain('slots');
});

it('MESO-005 the style alone relabels the days, and a floating split stays floating when its days change', function () {
    $user = buildWorld();
    build($user, ['template_id' => (string) buildTemplate()->id]);

    $floating = build($user, ['label_style' => 'floating'])->dayRows();
    $edited = build($user, ['days' => buildDays()])->dayRows();

    expect(array_column($floating, 'weekday'))->toBe([null, null])
        ->and(array_column($floating, 'label'))->toBe(['Day 1', 'Day 2'])
        ->and(array_column($edited, 'weekday'))->toBe([null, null]);
});

it('MESO-003 MESO-020 finishing keeps the priorities the build was given', function () {
    $user = buildWorld();
    build($user, ['template_id' => (string) buildTemplate()->id]);

    expect(build($user, ['finish' => true])->muscle_priorities)->toBe(['chest' => 'emphasize', 'quads' => 'grow']);
});

it('MESO-007 MESO-012 a build can start and finish in one call', function () {
    $user = buildWorld();

    $saved = build($user, ['template_id' => (string) buildTemplate()->id, 'name' => 'Block', 'finish' => true]);

    expect($saved->isSaved())->toBeTrue()
        ->and($saved->name)->toBe('Block')
        ->and($saved->is_resensitization)->toBeFalse();
});
