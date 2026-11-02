<?php

use App\Actions\Today\DeleteOffPlan;
use App\Actions\Today\LogOffPlan;
use App\Capabilities\Today\Data\OffPlanLogInput;
use App\Models\OffPlanEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

// What the off-plan writes save (DAY-001). The HTTP seam is OffPlanCapabilitiesTest's.
uses(RefreshDatabase::class);

const OFF_PLAN_ID = '01JBX0A1B2C3D4E5F6G7H8J9K0';

function offPlanInput(array $values = []): OffPlanLogInput
{
    return new OffPlanLogInput(...array_replace([
        'id' => OFF_PLAN_ID, 'date' => '2026-09-23', 'time' => '15:10',
        'kcal' => 350.0, 'protein_g' => 4.0, 'fat_g' => 18.0, 'carbs_g' => 45.0, 'name' => 'Birthday cake',
    ], $values));
}

it('DAY-001 logs food eaten off-plan as an entry on the day with no meal slot, under the id the client made', function () {
    $user = User::factory()->create();

    $entry = app(LogOffPlan::class)($user, null, offPlanInput());

    expect($entry->id)->toBe(OFF_PLAN_ID)
        ->and($entry->fresh()->only(['user_id', 'name', 'time', 'kcal', 'protein_g', 'fat_g', 'carbs_g']))->toBe([
            'user_id' => $user->id, 'name' => 'Birthday cake', 'time' => '15:10',
            'kcal' => 350.0, 'protein_g' => 4.0, 'fat_g' => 18.0, 'carbs_g' => 45.0,
        ])
        ->and($entry->fresh()->date->format('Y-m-d'))->toBe('2026-09-23');
});

it('DAY-001 a blank name is kept as none, so the entry shows as "Off-plan food"', function (?string $name) {
    $entry = app(LogOffPlan::class)(User::factory()->create(), null, offPlanInput(['name' => $name]));

    expect($entry->fresh()->name)->toBeNull();
})->with(['blank' => '   ', 'missing' => null]);

it('DAY-001 a name is trimmed', function () {
    $entry = app(LogOffPlan::class)(User::factory()->create(), null, offPlanInput(['name' => '  Cake  ']));

    expect($entry->fresh()->name)->toBe('Cake');
});

it('DAY-001 CAP-019 logging the same id again edits that entry instead of adding one', function () {
    $user = User::factory()->create();
    $first = app(LogOffPlan::class)($user, null, offPlanInput());

    app(LogOffPlan::class)($user, $first, offPlanInput(['kcal' => 420.0, 'time' => '16:00']));

    expect(OffPlanEntry::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($first->fresh()->only(['kcal', 'time']))->toBe(['kcal' => 420.0, 'time' => '16:00']);
});

it('DAY-001 deleting an entry takes it off the day', function () {
    $user = User::factory()->create();
    $entry = OffPlanEntry::factory()->for($user)->create();

    app(DeleteOffPlan::class)($user, $entry->id);

    expect(OffPlanEntry::query()->find($entry->id))->toBeNull();
});
