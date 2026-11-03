<?php

use App\Capabilities\Support\HostDefinition;
use App\Models\PlanWeek;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\SheetSeed;
use Tests\Support\TrainSeed;

// The HTTP seam of missed_week_resolve. The rules are ResolveMissedWeekTest's and MissedWeeksPromptTest's.

beforeEach(fn () => Carbon::setTestNow('2026-09-29 08:00:00'));
afterEach(fn () => Carbon::setTestNow());

function weekResolveOver(?User $user, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/missed_week_resolve', $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

it('SCH-058 refuses missed_week_resolve when signed out', function () {
    weekResolveOver(null, ['mesocycle_id' => '01JB6Q3V8T2K9WZ4M5N7P1R3S5', 'choice' => 'restart'])->assertUnauthorized();
});

it('SCH-058 answers 404 for another user\'s mesocycle', function () {
    $theirs = TrainSeed::mesocycle(TrainSeed::user());

    weekResolveOver(TrainSeed::user(), ['mesocycle_id' => $theirs->id, 'choice' => 'restart'])->assertNotFound();
});

it('SCH-058 answers 422 naming choice when it is not restart or end', function () {
    $user = TrainSeed::user();

    weekResolveOver($user, ['mesocycle_id' => TrainSeed::mesocycle($user)->id, 'choice' => 'bogus'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'choice');
});

it('SCH-058 CAP-011 restart moves the start and invalidates the strip of the week and of the weeks a deload moved between', function () {
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    PlanWeek::factory()->for($user)->create(['start_date' => '2026-09-21', 'is_deload' => true]);

    $response = weekResolveOver($user, ['mesocycle_id' => $mesocycle->id, 'choice' => 'restart'])
        ->assertOk()
        ->assertJsonPath('data.toast', 'Week restarted');

    expect($response->json('data.invalidates'))->toContain('today_show{"date":"2026-09-29"}', 'today_show{}', 'train_show{}', 'week_show{"date":"2026-09-28"}', 'week_show{"date":"2026-10-12"}', 'week_show{"date":"2026-09-21"}')
        ->and($mesocycle->fresh()->start_date->format('Y-m-d'))->toBe('2026-09-28');
});

it('SCH-058 AGT-010 is destructive, so the agent needs the user\'s say-so', function () {
    expect(HostDefinition::isDestructive(app(CapabilityRegistry::class)->get('missed_week_resolve')))->toBeTrue();
});

it('SCH-058 CAP-011 SCH-023 restart invalidates the days of the old placement and every day of the last strip week', function () {
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    SheetSeed::day($user, '2026-10-20');

    $invalidates = weekResolveOver($user, ['mesocycle_id' => $mesocycle->id, 'choice' => 'restart'])->assertOk()->json('data.invalidates');

    // 14 and 27 Sep held Meso 2's missed sessions before the start moved; 25 Oct ends the strip week of the 20th.
    expect($invalidates)->toContain('today_show{"date":"2026-09-14"}', 'today_show{"date":"2026-09-27"}', 'week_show{"date":"2026-09-14"}', 'today_show{"date":"2026-10-25"}', 'week_show{"date":"2026-10-25"}', 'plan_show{}')
        ->and($invalidates)->not->toContain('today_show{"date":"2026-10-26"}');
});

it('SCH-058 CAP-011 SCH-023 end invalidates every day from the plan week in progress through the last strip week', function () {
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);
    SheetSeed::day($user, '2026-10-20');

    $invalidates = weekResolveOver($user, ['mesocycle_id' => $mesocycle->id, 'choice' => 'end'])->assertOk()->json('data.invalidates');

    expect($invalidates)->toContain('today_show{"date":"2026-09-28"}', 'today_show{"date":"2026-10-20"}', 'today_show{"date":"2026-10-25"}', 'plan_show{}')
        ->and($invalidates)->not->toContain('today_show{"date":"2026-09-27"}');
});

it('SCH-058 answers 422 for end when no answer is due', function () {
    Carbon::setTestNow('2026-09-09 08:00:00');
    $user = TrainSeed::user();
    $mesocycle = TrainSeed::mesocycle($user);

    weekResolveOver($user, ['mesocycle_id' => $mesocycle->id, 'choice' => 'end'])->assertStatus(422);

    expect($mesocycle->fresh()->ended_on)->toBeNull();
});
