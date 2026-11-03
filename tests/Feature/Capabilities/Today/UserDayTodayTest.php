<?php

use App\Capabilities\Support\ReadKey;
use App\Capabilities\Today\TodayReads;
use App\Models\User;
use App\Models\WeighIn;
use App\Models\WeighInScheduleEntry;
use App\Support\Today\TodayWeight;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\TodaySeed;

// SET-012: Today, the week strip and Today's weigh-ins count days in the user's time zone.

afterEach(fn () => Carbon::setTestNow());

function zonedTodayUser(string $zone): User
{
    $user = TodaySeed::user();
    TodaySeed::mesocycle($user);
    TodaySeed::targets($user, last: '2026-10-16');
    $user->forceFill(['time_zone' => $zone])->save();

    return $user;
}

it('SET-012 opens Today and the week strip on the user\'s local day', function (string $zone, string $today) {
    Carbon::setTestNow('2026-10-09 21:58:00');
    $user = zonedTodayUser($zone);

    $data = test()->actingAs($user)->postJson('/capabilities/today_show')->assertOk()->json('data');
    $week = test()->actingAs($user)->postJson('/capabilities/week_show')->assertOk()->json('data');

    expect($data['date'])->toBe($today)
        ->and(array_column(array_filter($week['days'], fn (array $day) => $day['is_today']), 'date'))->toBe([$today]);
})->with([
    'Brisbane' => ['Australia/Brisbane', '2026-10-10'],
    'UTC' => ['UTC', '2026-10-09'],
]);

it('SET-012 shows a weigh-in on the user\'s local day', function (string $zone, string $shownOn, string $notOn) {
    Carbon::setTestNow('2026-10-09 21:58:00');
    $user = zonedTodayUser($zone);
    // Today shows a weigh-in row on scheduled days only; the seed's are Wednesday and Saturday.
    WeighInScheduleEntry::query()->where('user_id', $user->id)->firstOrFail()->update(['weekdays' => [1, 2, 3, 4, 5, 6, 7]]);
    WeighIn::factory()->for($user)->create(['weight_kg' => 81.3, 'weighed_at' => '2026-10-09 21:58:00']);

    $on = test()->actingAs($user)->postJson('/capabilities/today_show', ['date' => $shownOn])->assertOk()->json('data');
    $off = test()->actingAs($user)->postJson('/capabilities/today_show', ['date' => $notOn])->assertOk()->json('data');

    expect(array_column($on['weigh_ins'], 'weight_text'))->toContain('81.3 kg')
        ->and(array_column($off['weigh_ins'], 'weight_text'))->not->toContain('81.3 kg');
})->with([
    'Brisbane' => ['Australia/Brisbane', '2026-10-10', '2026-10-09'],
    'UTC' => ['UTC', '2026-10-09', '2026-10-10'],
]);

it('SET-012 a write to the user\'s local today drops the undated Today and week reads', function () {
    Carbon::setTestNow('2026-10-09 21:58:00');
    $user = zonedTodayUser('Australia/Brisbane');

    $invalidates = test()->actingAs($user)->postJson('/capabilities/off_plan_log', [
        'id' => (string) Str::ulid(), 'date' => '2026-10-10', 'time' => '07:30',
        'kcal' => 350, 'protein_g' => 4, 'fat_g' => 18, 'carbs_g' => 45, 'name' => 'Birthday cake',
    ], ['Idempotency-Key' => (string) Str::ulid()])->assertOk()->json('data.invalidates');

    expect($invalidates)->toContain(ReadKey::for('today_show', ['date' => '2026-10-10']), ReadKey::for('today_show'))
        ->and(TodayReads::weekKeys(['2026-10-10']))->toContain(ReadKey::for('week_show'))
        ->and(TodayReads::keys(['2026-10-09']))->not->toContain(ReadKey::for('today_show'))
        ->and(TodayReads::weekKeys(['2026-10-09']))->not->toContain(ReadKey::for('week_show'));
});

it('SET-012 with no one signed in, today for the undated reads is the UTC day', function () {
    Carbon::setTestNow('2026-10-09 21:58:00');

    expect(TodayReads::keys(['2026-10-09']))->toContain(ReadKey::for('today_show'))
        ->and(TodayReads::keys(['2026-10-10']))->not->toContain(ReadKey::for('today_show'));
});

it('SET-012 buckets the current weight\'s trend by the user\'s local days, even after the zone changes', function () {
    $user = User::factory()->create();
    // 23:00 and 01:00 in Brisbane: one UTC day, two local days.
    WeighIn::factory()->for($user)->create(['weight_kg' => 80.0, 'weighed_at' => '2026-10-08 13:00:00']);
    WeighIn::factory()->for($user)->create(['weight_kg' => 90.0, 'weighed_at' => '2026-10-08 15:00:00']);

    expect(TodayWeight::kg($user->fresh(), null))->toBe(80.0);

    $user->forceFill(['time_zone' => 'Australia/Brisbane'])->save();

    expect(TodayWeight::kg($user->fresh(), null))->toBe(81.0);
});
