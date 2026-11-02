<?php

use App\Models\Phase;
use App\Models\PlanWeek;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\TodaySeed;
use Tests\Support\TrainSeed;

// The HTTP seam of away_week_set. The rules are SetAwayWeeksTest's.

afterEach(fn () => Carbon::setTestNow());

function awaySet(?User $user, array $input): mixed
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson('/capabilities/away_week_set', $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

it('SCH-060 refuses away_week_set when signed out', function () {
    awaySet(null, ['week_starts' => ['2026-09-26'], 'away' => true])->assertUnauthorized();
});

it('SCH-060 answers 422 naming the week that is not a date', function () {
    awaySet(User::factory()->create(), ['week_starts' => ['2026-02-30'], 'away' => true])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'week_starts.0');
});

it('SCH-060 answers 422 for a week the sheet would not offer, saving nothing', function () {
    Carbon::setTestNow('2026-09-23 10:00:00');
    $user = TodaySeed::user();
    TrainSeed::phase($user, 'fatLoss', '2026-09-12', '2026-12-05');

    awaySet($user, ['week_starts' => ['2026-09-19'], 'away' => true])->assertStatus(422);

    expect(PlanWeek::query()->where('is_away', true)->count())->toBe(0);
});

it('SCH-060 CAP-013 marks a week away with a toast, drops what it changed, and its undo puts the end date back', function () {
    Carbon::setTestNow('2026-09-23 10:00:00');
    $user = TodaySeed::user();
    $phase = TrainSeed::phase($user, 'fatLoss', '2026-09-12', '2026-12-05');

    $response = awaySet($user, ['week_starts' => ['2026-09-26'], 'away' => true, 'training' => 'pause', 'calories' => 'maintenance'])
        ->assertOk()
        ->assertJsonPath('data.toast', 'Week marked away');
    expect($response->json('data.invalidates'))->toContain(
        'today_show{"date":"2026-09-26"}', 'week_show{"date":"2026-10-02"}', 'away_week_show{"week_start":"2026-10-03"}', 'plan_show{}',
        'phase_show{"phase_id":"'.$phase->id.'"}', 'train_show{}',
    )->and($phase->fresh()->end_date->format('Y-m-d'))->toBe('2026-12-12');

    $this->actingAs($user)->postJson('/capabilities/undo_apply', ['token' => $response->json('data.undo')], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk();

    expect(Phase::query()->find($phase->id)->end_date->format('Y-m-d'))->toBe('2026-12-05')
        ->and(PlanWeek::query()->where('user_id', $user->id)->where('is_away', true)->count())->toBe(0);
});
