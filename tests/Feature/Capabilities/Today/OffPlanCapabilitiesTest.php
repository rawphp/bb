<?php

use App\Models\OffPlanEntry;
use App\Models\User;
use Illuminate\Support\Str;

// The HTTP seam of off_plan_log and off_plan_delete. The rules are OffPlanTest's.

function offPlanOver(?User $user, string $name, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson("/capabilities/{$name}", $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

function offPlanLog(array $values = []): array
{
    return array_replace([
        'id' => (string) Str::ulid(), 'date' => '2026-09-23', 'time' => '15:10',
        'kcal' => 350, 'protein_g' => 4, 'fat_g' => 18, 'carbs_g' => 45, 'name' => 'Birthday cake',
    ], $values);
}

it('DAY-001 refuses off_plan_log and off_plan_delete when signed out', function (string $name, array $input) {
    offPlanOver(null, $name, $input)->assertUnauthorized();
})->with([
    'off_plan_log' => fn () => ['off_plan_log', offPlanLog()],
    'off_plan_delete' => fn () => ['off_plan_delete', ['id' => (string) Str::ulid()]],
]);

it('DAY-001 CAP-019 answers 404 for another user\'s entry, logged again or deleted', function (string $name) {
    $theirs = OffPlanEntry::factory()->for(User::factory()->create())->create();

    offPlanOver(User::factory()->create(), $name, $name === 'off_plan_log' ? offPlanLog(['id' => $theirs->id]) : ['id' => $theirs->id])
        ->assertNotFound();

    expect($theirs->fresh()->kcal)->toBe(350.0);
})->with(['off_plan_log', 'off_plan_delete']);

it('DAY-001 answers 422 naming kcal when it is negative', function () {
    offPlanOver(User::factory()->create(), 'off_plan_log', offPlanLog(['kcal' => -5]))
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'kcal');
});

it('DAY-001 logs an entry and invalidates that day\'s Today', function () {
    $user = User::factory()->create();
    $input = offPlanLog();

    offPlanOver($user, 'off_plan_log', $input)
        ->assertOk()
        ->assertJsonPath('data.invalidates', ['today_show{"date":"2026-09-23"}', 'day_redistribute_show{"date":"2026-09-23"}']);

    expect(OffPlanEntry::query()->find($input['id'])?->user_id)->toBe($user->id);
});

it('DAY-001 deletes the user\'s own entry', function () {
    $user = User::factory()->create();
    $entry = OffPlanEntry::factory()->for($user)->create(['date' => '2026-09-23']);

    offPlanOver($user, 'off_plan_delete', ['id' => $entry->id])
        ->assertOk()
        ->assertJsonPath('data.invalidates', ['today_show{"date":"2026-09-23"}', 'day_redistribute_show{"date":"2026-09-23"}']);

    expect(OffPlanEntry::query()->find($entry->id))->toBeNull();
});

it('DAY-001 an entry with no name is deleted as "Off-plan food"', function () {
    $user = User::factory()->create();
    $entry = OffPlanEntry::factory()->for($user)->create(['name' => null]);

    offPlanOver($user, 'off_plan_delete', ['id' => $entry->id])
        ->assertOk()
        ->assertJsonPath('data.toast', 'Off-plan food deleted');
});
