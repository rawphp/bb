<?php

use App\Models\DayTarget;
use App\Models\OtherActivity;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\SheetSeed;
use Tests\Support\TrainSeed;

// The HTTP seam of other_activity_save and other_activity_delete. The rules are OtherActivityTest's. Today is
// Tuesday 22 September 2026.

beforeEach(fn () => Carbon::setTestNow('2026-09-22 08:00:00'));
afterEach(fn () => Carbon::setTestNow());

function activityOver(?User $user, string $name, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson("/capabilities/{$name}", $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

function activityInput(array $values = []): array
{
    return array_replace(['id' => (string) Str::ulid(), 'date' => '2026-09-23', 'name' => 'Run', 'start' => '07:00', 'minutes' => 45, 'intensity' => 'moderate'], $values);
}

it('SCH-032 refuses other_activity_save and other_activity_delete when signed out', function (string $name, array $input) {
    activityOver(null, $name, $input)->assertUnauthorized();
})->with([
    'other_activity_save' => fn () => ['other_activity_save', activityInput()],
    'other_activity_delete' => fn () => ['other_activity_delete', ['id' => (string) Str::ulid()]],
]);

it('SCH-032 CAP-019 answers 404 for another user\'s activity, saved again or deleted', function (string $name) {
    $theirs = OtherActivity::factory()->for(User::factory()->create())->create(['id' => (string) Str::ulid(), 'date' => '2026-09-23', 'minutes' => 30]);
    $user = TrainSeed::user();
    SheetSeed::day($user);

    activityOver($user, $name, $name === 'other_activity_save' ? activityInput(['id' => $theirs->id, 'minutes' => 90]) : ['id' => $theirs->id])
        ->assertNotFound();

    expect($theirs->fresh()?->minutes)->toBe(30);
})->with(['other_activity_save', 'other_activity_delete']);

it('NUT-005 answers 422 naming the bad field', function (string $name, array $input, string $field) {
    activityOver(TrainSeed::user(), $name, $input)
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', $field);
})->with([
    'other_activity_save at a rest intensity' => fn () => ['other_activity_save', activityInput(['intensity' => 'rest']), 'intensity'],
    'other_activity_delete with no id' => ['other_activity_delete', [], 'id'],
]);

it('SCH-032 SCH-056 CAP-013 saving re-targets the day with a toast, and undo puts the day back without the activity', function () {
    $user = TrainSeed::user();
    $day = SheetSeed::day($user);
    $before = $day->fresh()->only(['day_type', 'kcal', 'meals']);

    $response = activityOver($user, 'other_activity_save', activityInput())->assertOk()->assertJsonPath('data.toast', 'Meals updated for Run');
    expect($response->json('data.invalidates'))->toContain('today_show{"date":"2026-09-23"}', 'day_adjust_show{"date":"2026-09-23"}', 'week_show{"date":"2026-09-23"}')
        ->and(OtherActivity::query()->count())->toBe(1);

    activityOver($user, 'undo_apply', ['token' => $response->json('data.undo')])->assertOk();

    expect(DayTarget::query()->find($day->id)->only(['day_type', 'kcal', 'meals']))->toEqual($before)
        ->and(OtherActivity::query()->count())->toBe(0);
});

it('SCH-032 AGT-010 CAP-013 deleting is marked destructive, re-targets the day, and undo brings the activity back', function () {
    $user = TrainSeed::user();
    $day = SheetSeed::day($user);
    $input = activityInput();
    activityOver($user, 'other_activity_save', $input)->assertOk();
    $saved = $day->fresh()->only(['day_type', 'kcal', 'meals']);

    $response = activityOver($user, 'other_activity_delete', ['id' => $input['id']])->assertOk()->assertJsonPath('data.toast', 'Meals updated: Run removed');
    expect($response->json('data.invalidates'))->toContain('today_show{"date":"2026-09-23"}', 'week_show{"date":"2026-09-23"}')
        ->and(OtherActivity::query()->count())->toBe(0)
        ->and(app(CapabilityRegistry::class)->get('other_activity_delete')->tags)->toContain('destructive', 'reversible');

    activityOver($user, 'undo_apply', ['token' => $response->json('data.undo')])->assertOk();

    expect(DayTarget::query()->find($day->id)->only(['day_type', 'kcal', 'meals']))->toEqual($saved)
        ->and(OtherActivity::query()->count())->toBe(1);
});
