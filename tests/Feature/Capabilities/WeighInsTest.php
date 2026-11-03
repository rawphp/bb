<?php

use App\Capabilities\Reads\WeighIns\WeighInsPayload;
use App\Capabilities\Support\HostDefinition;
use App\Domain\Model\WeighIn as WeighInPoint;
use App\Domain\Tokens\TokenScope;
use App\Domain\WeighIns\BodyMeasures;
use App\Domain\WeighIns\WeighInSchedule;
use App\Domain\Weight\TrendWeight;
use App\Enums\MeasurementSource;
use App\Models\PersonalAccessToken;
use App\Models\Phase;
use App\Models\Profile;
use App\Models\Step;
use App\Models\User;
use App\Models\WeighIn;
use App\Models\WeighInScheduleEntry;
use App\Support\Today\TodayWeight;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\ScreenFixture;

// The HTTP seam of weigh_ins_show, weigh_in_log, weigh_in_update, weigh_in_delete, weigh_in_schedule_set and
// steps_log. The rules are unit tests (tests/Unit/Domain/WeighIns and tests/Unit/Actions/WeighIns).

afterEach(fn () => Carbon::setTestNow());

const WEIGH_IN_NAMES = ['weigh_ins_show', 'weigh_in_log', 'weigh_in_update', 'weigh_in_delete', 'weigh_in_schedule_set', 'steps_log'];

function weighInCall(?User $user, string $name, array $input = [], array $headers = []): TestResponse
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson("/capabilities/{$name}", $input, ['Idempotency-Key' => (string) Str::ulid(), ...$headers]);
}

function weighInInput(array $change = []): array
{
    return [
        'id' => (string) Str::ulid(),
        'weighed_at' => '2026-10-10T08:35:00+00:00',
        'weight' => ['value' => 82.5, 'unit' => 'kg'],
        ...$change,
    ];
}

/** A Health bridge token of the user's (INT-001), as its bearer secret. */
function healthBridgeToken(User $user): string
{
    $secret = test()->actingAs($user)->postJson('/capabilities/token_create', [
        'id' => (string) Str::ulid(),
        'name' => 'Health bridge',
        'expires_in_days' => 90,
        'scope' => 'health_bridge',
        'capabilities' => [],
    ], ['Idempotency-Key' => (string) Str::ulid()])->assertOk()->json('data.token.plain_text');
    Auth::forgetGuards();

    return $secret;
}

/** The scenario the weigh_ins_show screen fixture shows, on Saturday 10 October 2026. */
function weighInScenario(): User
{
    $user = User::factory()->create();
    Profile::factory()->for($user)->create(['weight_unit' => 'kg', 'height_unit' => 'cm']);
    WeighInScheduleEntry::factory()->for($user)->create(['weekdays' => [1, 3, 5], 'time' => '07:00']);
    WeighInScheduleEntry::factory()->for($user)->create(['weekdays' => [6], 'time' => '08:30']);

    return $user;
}

it('CAP-011 CAP-013 AGT-010 registers the weigh-in capabilities on every surface, the two that delete destructive and none reversible', function () {
    $registry = app(CapabilityRegistry::class);

    foreach (WEIGH_IN_NAMES as $name) {
        $definition = $registry->get($name);
        expect($definition->surfaces)->toBe(HostDefinition::SURFACES)
            ->and(HostDefinition::isReversible($definition))->toBeFalse($name)
                        // weigh_in_schedule_set deletes every entry it is not sent (WT-007's Delete schedule).
            ->and(HostDefinition::isDestructive($definition))->toBe(in_array($name, ['weigh_in_delete', 'weigh_in_schedule_set'], true), $name);
    }
    // A weigh-in also moves the trend each phase's weights are shown from (PLAN-004).
    foreach (['weigh_in_log', 'weigh_in_update', 'weigh_in_delete'] as $name) {
        expect(HostDefinition::affects($registry->get($name)))->toBe(['weigh_ins_show', 'today_show', 'phase_show'], $name);
    }
    foreach (['weigh_in_schedule_set', 'steps_log'] as $name) {
        expect(HostDefinition::affects($registry->get($name)))->toBe(['weigh_ins_show', 'today_show'], $name);
    }
    expect($registry->get('weigh_ins_show')->readOnly)->toBeTrue();
});

it('WT-004 refuses every weigh-in capability when signed out', function (string $name, array $input) {
    weighInCall(null, $name, $input)->assertUnauthorized();
})->with([
    'weigh_ins_show' => ['weigh_ins_show', []],
    'weigh_in_log' => ['weigh_in_log', weighInInput()],
    'weigh_in_update' => ['weigh_in_update', weighInInput()],
    'weigh_in_delete' => ['weigh_in_delete', ['id' => '01JBX0A1B2C3D4E5F6G7H8J9K0']],
    'weigh_in_schedule_set' => ['weigh_in_schedule_set', ['entries' => [['weekdays' => [1, 4], 'time' => '07:00']]]],
    'steps_log' => ['steps_log', ['date' => '2026-10-09', 'steps' => 8412]],
]);

it('WT-001 WT-004 WT-005 WT-010 WT-020 WT-021 shows the weigh-ins, missed days, trend, schedule and steps', function () {
    Carbon::setTestNow('2026-10-10 12:00:00');
    // Another user's profile first, so a lookup that forgot the owner would find theirs.
    $other = User::factory()->create();
    Profile::factory()->for($other)->create(['weight_unit' => 'lb', 'height_unit' => 'feetInches']);
    $user = weighInScenario();
    // The fixture's IDs are placeholders, not ULIDs.
    $ids = [
        '01JA00000000000000000WEIGH1' => WeighIn::factory()->for($user)->create(['weight_kg' => 83.2, 'weighed_at' => '2026-10-04 09:05:00'])->id,
        '01JA00000000000000000WEIGH2' => WeighIn::factory()->for($user)->create(['weight_kg' => 82.9, 'weighed_at' => '2026-10-05 07:00:00'])->id,
        '01JA00000000000000000WEIGH3' => WeighIn::factory()->for($user)->create(['weight_kg' => 82.5, 'waist_cm' => 86, 'weighed_at' => '2026-10-10 08:35:00'])->id,
    ];
    Step::factory()->for($user)->create(['date' => '2026-10-09', 'count' => 8412]);
    Step::factory()->for($user)->shortcut()->create(['date' => '2026-10-08', 'count' => 10230]);
    // Another user's rows, none of which may show.
    WeighIn::factory()->for($other)->create(['weighed_at' => '2026-10-09 07:00:00']);
    Step::factory()->for($other)->create(['date' => '2026-10-07', 'count' => 500]);
    WeighInScheduleEntry::factory()->for($other)->create(['weekdays' => [2, 4], 'time' => '06:00']);

    $payload = weighInCall($user, 'weigh_ins_show')->assertOk()->json('data');

    $expected = ScreenFixture::load('weigh_ins_show');
    foreach ($expected['rows'] as $index => $row) {
        $expected['rows'][$index]['id'] = $ids[$row['id']] ?? $row['id'];
    }
    expect($payload)->toBe($expected);
    assertMatchesScreenFixture('weigh_ins_show', $payload);
});

it('WT-004 WT-010 shows no rows and no trend before the first weigh-in', function () {
    Carbon::setTestNow('2026-10-10 12:00:00');

    $payload = weighInCall(weighInScenario(), 'weigh_ins_show')->assertOk()->json('data');

    expect($payload)->toBe(ScreenFixture::load('weigh_ins_show.empty'));
    assertMatchesScreenFixture('weigh_ins_show', $payload);
});

it('WT-021 shows weight in pounds and waist in inches for a user who sees height in feet and inches', function () {
    Carbon::setTestNow('2026-10-10 12:00:00');
    $user = User::factory()->create();
    Profile::factory()->for($user)->create(['weight_unit' => 'lb', 'height_unit' => 'feetInches']);
    WeighIn::factory()->for($user)->create(['weight_kg' => 90.72, 'waist_cm' => 86.4, 'weighed_at' => '2026-10-10 07:00:00']);

    $payload = weighInCall($user, 'weigh_ins_show')->assertOk()->json('data');

    expect($payload['units'])->toBe(['weight' => 'lb', 'waist' => 'in'])
        ->and($payload['rows'][0])->toMatchArray(['weight_label' => '200.0 lb', 'weight_field' => '200', 'waist_label' => '34 in', 'waist_field' => '34']);
});

it('WT-002 logs a weigh-in as typed and names the reads it changed', function () {
    Carbon::setTestNow('2026-10-10 12:00:00');
    $user = User::factory()->create();
    $phase = Phase::factory()->for($user)->create();
    Phase::factory()->create();
    $input = weighInInput(['waist' => ['value' => 86, 'unit' => 'cm']]);

    weighInCall($user, 'weigh_in_log', $input)
        ->assertOk()
        ->assertJsonPath('data.invalidates', ['weigh_ins_show{}', 'today_show{"date":"2026-10-10"}', 'today_show{}', 'phase_show{"phase_id":"'.$phase->id.'"}']);

    expect(WeighIn::query()->findOrFail($input['id']))
        ->user_id->toBe($user->id)
        ->weight_kg->toBe(82.5)
        ->waist_cm->toBe(86.0)
        ->source->toBe(MeasurementSource::Manual);
});

it('WT-003 WT-020 marks what the Health bridge token posts as the bridge\'s, and never lets it overwrite typed steps', function () {
    $user = User::factory()->create();
    Step::factory()->for($user)->create(['date' => '2026-10-09', 'count' => 8412]);
    $secret = healthBridgeToken($user);
    $input = weighInInput();

    test()->withHeader('Authorization', "Bearer {$secret}")
        ->postJson('/capabilities/weigh_in_log', $input, ['Idempotency-Key' => (string) Str::ulid()])->assertOk();
    test()->withHeader('Authorization', "Bearer {$secret}")
        ->postJson('/capabilities/steps_log', ['date' => '2026-10-09', 'steps' => 12000], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk()
        ->assertJsonPath('data.invalidates', []);
    test()->withHeader('Authorization', "Bearer {$secret}")
        ->postJson('/capabilities/steps_log', ['date' => '2026-10-08', 'steps' => 10230], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk();

    expect(WeighIn::query()->findOrFail($input['id'])->source)->toBe(MeasurementSource::Shortcut)
        ->and(PersonalAccessToken::query()->sole()->scope->value)->toBe('health_bridge')
        ->and(Step::query()->where('user_id', $user->id)->orderBy('date')->get(['count', 'source'])->map(fn (Step $s) => [$s->count, $s->source])->all())
        ->toBe([[10230, MeasurementSource::Shortcut], [8412, MeasurementSource::Manual]]);
});

it('WT-003 WT-020 marks what any other token posts as typed, which the bridge then cannot overwrite', function () {
    $user = User::factory()->create();
    $cli = test()->actingAs($user)->postJson('/capabilities/token_create', [
        'id' => (string) Str::ulid(),
        'name' => 'Laptop CLI',
        'expires_in_days' => 90,
        'scope' => 'everything',
        'capabilities' => [],
    ], ['Idempotency-Key' => (string) Str::ulid()])->assertOk()->json('data.token.plain_text');
    Auth::forgetGuards();
    $input = weighInInput();

    test()->withHeader('Authorization', "Bearer {$cli}")
        ->postJson('/capabilities/weigh_in_log', $input, ['Idempotency-Key' => (string) Str::ulid()])->assertOk();
    test()->withHeader('Authorization', "Bearer {$cli}")
        ->postJson('/capabilities/steps_log', ['date' => '2026-10-09', 'steps' => 8412], ['Idempotency-Key' => (string) Str::ulid()])->assertOk();
    Auth::forgetGuards();
    $bridge = healthBridgeToken($user);
    test()->withHeader('Authorization', "Bearer {$bridge}")
        ->postJson('/capabilities/steps_log', ['date' => '2026-10-09', 'steps' => 3000], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertOk()
        ->assertJsonPath('data.invalidates', []);

    expect(WeighIn::query()->findOrFail($input['id'])->source)->toBe(MeasurementSource::Manual)
        ->and(Step::query()->where('user_id', $user->id)->sole())->count->toBe(8412)->source->toBe(MeasurementSource::Manual);
});

it('WT-003 WT-020 marks what a Health bridge token posts as the bridge\'s over MCP too, so it never overwrites typed steps', function () {
    $user = User::factory()->create();
    Step::factory()->for($user)->create(['date' => '2026-10-09', 'count' => 8412]);
    $token = PersonalAccessToken::factory()->for($user, 'tokenable')
        ->create(['scope' => TokenScope::HealthBridge, 'abilities' => TokenScope::HEALTH_BRIDGE]);
    $bridge = $user->withAccessToken($token);
    $registry = app(CapabilityRegistry::class);
    $id = (string) Str::ulid();

    expect($registry->invoke('steps_log', ['date' => '2026-10-09', 'steps' => 0], ['caller' => 'mcp', 'actor' => $bridge])->isOk())->toBeTrue()
        ->and($registry->invoke('weigh_in_log', weighInInput(['id' => $id]), ['caller' => 'mcp', 'actor' => $bridge])->isOk())->toBeTrue()
        ->and(Step::query()->where('user_id', $user->id)->sole())->count->toBe(8412)->source->toBe(MeasurementSource::Manual)
        ->and(WeighIn::query()->findOrFail($id)->source)->toBe(MeasurementSource::Shortcut);
});

it('WT-002 WT-004 counts a weigh-in on the app\'s day, UTC, as Today does: the one it refreshes and the one it fills', function () {
    // The app has no per-user time zone: today_show's "today" is the UTC date, so a reading belongs to its UTC day.
    Carbon::setTestNow('2026-10-10 12:00:00');
    $user = User::factory()->create();
    WeighInScheduleEntry::factory()->for($user)->create(['weekdays' => [4, 5], 'time' => '07:00']);
    WeighIn::factory()->for($user)->create(['weighed_at' => '2026-10-08 07:00:00']);

    // 06:58 on Saturday at +10:00 is 20:58 on Friday in UTC.
    weighInCall($user, 'weigh_in_log', weighInInput(['weighed_at' => '2026-10-10T06:58:00+10:00']))
        ->assertOk()
        ->assertJsonPath('data.invalidates', ['weigh_ins_show{}', 'today_show{"date":"2026-10-09"}']);

    $rows = weighInCall($user, 'weigh_ins_show')->json('data.rows');
    expect(array_column($rows, 'date_label'))->toBe(['Fri, 9 Oct', 'Thu, 8 Oct'])
        ->and(array_column($rows, 'is_missed'))->toBe([false, false])
        ->and($rows[0]['weighed_at'])->toBe('2026-10-09T20:58:00+00:00');
});

it('WT-020 logs a typed step count and names the day it changed', function () {
    Carbon::setTestNow('2026-10-10 12:00:00');
    $user = User::factory()->create();

    weighInCall($user, 'steps_log', ['date' => '2026-10-09', 'steps' => 8412])
        ->assertOk()
        ->assertJsonPath('data.invalidates', ['weigh_ins_show{}', 'today_show{"date":"2026-10-09"}']);

    expect(Step::query()->where('user_id', $user->id)->sole())->count->toBe(8412)->source->toBe(MeasurementSource::Manual);
});

it('WT-004 corrects and deletes a weigh-in and names its days', function () {
    Carbon::setTestNow('2026-10-10 12:00:00');
    $user = User::factory()->create();
    $phase = Phase::factory()->for($user)->create();
    Phase::factory()->create();
    $weighIn = WeighIn::factory()->for($user)->create(['weight_kg' => 81.4, 'weighed_at' => '2026-10-08 06:00:00']);
    $phaseKey = 'phase_show{"phase_id":"'.$phase->id.'"}';

    weighInCall($user, 'weigh_in_update', weighInInput(['id' => $weighIn->id, 'weighed_at' => '2026-10-09T06:00:00+00:00', 'weight' => ['value' => 81.2, 'unit' => 'kg']]))
        ->assertOk()
        ->assertJsonPath('data.invalidates', ['weigh_ins_show{}', 'today_show{"date":"2026-10-08"}', 'today_show{"date":"2026-10-09"}', $phaseKey]);
    expect($weighIn->fresh()->weight_kg)->toBe(81.2);

    weighInCall($user, 'weigh_in_delete', ['id' => $weighIn->id])
        ->assertOk()
        ->assertJsonPath('data.invalidates', ['weigh_ins_show{}', 'today_show{"date":"2026-10-09"}', $phaseKey]);
    expect($weighIn->fresh())->toBeNull();
});

it('WT-001 WT-007 replaces the schedule and shows it', function () {
    Carbon::setTestNow('2026-10-10 12:00:00');
    $user = weighInScenario();

    // Today shows the weigh-in on each scheduled day: every day of the week ahead can change (OFF-004 keeps them warm).
    weighInCall($user, 'weigh_in_schedule_set', ['entries' => [['weekdays' => [7, 2], 'time' => '06:45']]])
        ->assertOk()
        ->assertJsonPath('data.invalidates', [
            'weigh_ins_show{}',
            'today_show{"date":"2026-10-10"}',
            'today_show{}',
            'today_show{"date":"2026-10-11"}',
            'today_show{"date":"2026-10-12"}',
            'today_show{"date":"2026-10-13"}',
            'today_show{"date":"2026-10-14"}',
            'today_show{"date":"2026-10-15"}',
            'today_show{"date":"2026-10-16"}',
        ]);

    expect(weighInCall($user, 'weigh_ins_show')->json('data.schedule.entries'))->toBe([
        ['weekdays' => [2, 7], 'days_label' => 'Tue, Sun', 'time' => '06:45', 'time_label' => '6:45 AM', 'can_delete' => false],
    ]);
});

it('CAP-019 WT-004 answers 404 for another user\'s weigh-in, or a new one under its ID', function () {
    $theirs = WeighIn::factory()->create(['weight_kg' => 81.4]);
    $user = User::factory()->create();

    weighInCall($user, 'weigh_in_log', weighInInput(['id' => $theirs->id]))->assertNotFound();
    weighInCall($user, 'weigh_in_update', weighInInput(['id' => $theirs->id]))->assertNotFound();
    weighInCall($user, 'weigh_in_delete', ['id' => $theirs->id])->assertNotFound();

    expect($theirs->fresh())->weight_kg->toBe(81.4)
        ->and(WeighIn::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('WT-002 WT-004 WT-007 WT-020 answers 422 saying why', function () {
    $user = User::factory()->create();
    $mine = WeighIn::factory()->for($user)->create(['weight_kg' => 81.4]);

    weighInCall($user, 'weigh_in_log', weighInInput(['weight' => ['value' => 1000, 'unit' => 'kg']]))
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'Enter a weight above 0 and up to 999.99 kg.');
    weighInCall($user, 'weigh_in_schedule_set', ['entries' => [['weekdays' => [3], 'time' => '07:00']]])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'entries');
    weighInCall($user, 'steps_log', ['steps' => 8412])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'date');
    weighInCall($user, 'weigh_in_update', weighInInput(['id' => $mine->id, 'weight' => ['value' => 1000, 'unit' => 'kg']]))
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'Enter a weight above 0 and up to 999.99 kg.');
    weighInCall($user, 'weigh_in_delete', ['id' => 'not-a-ulid'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'id');
    // One plain message, not Laravel's date rules' two (the client shows it as given, OFF-011).
    weighInCall($user, 'weigh_in_log', weighInInput(['weighed_at' => '2026-02-30T07:00:00+00:00']))
        ->assertStatus(422)
        ->assertJsonCount(1, 'error.violations')
        ->assertJsonPath('error.violations.0.field', 'weighed_at')
        ->assertJsonPath('error.violations.0.message', 'Enter a real date and time.');
    weighInCall($user, 'steps_log', ['date' => '2026-02-30', 'steps' => 8412])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'date');

    expect($mine->fresh()->weight_kg)->toBe(81.4)
        ->and(WeighIn::query()->count())->toBe(1)
        ->and(WeighInScheduleEntry::query()->count())->toBe(0)
        ->and(Step::query()->count())->toBe(0);
});

/**
 * A weighInScenario() user with a year of history: a weigh-in every Mon, Wed and Fri at 07:00 from 2025-10-06 to
 * 2026-10-09, each 0.05 kg under the last from 90.00, and a step row every day in that range. Every Saturday is
 * missed.
 *
 * @return array{User, list<WeighIn>, list<string>} the user, the weigh-ins oldest first, every step date newest first
 */
function weighInYear(): array
{
    $user = weighInScenario();
    $weighIns = [];
    $dates = [];
    for ($day = Carbon::parse('2025-10-06'); $day->lte('2026-10-09'); $day = $day->addDay()) {
        if (in_array($day->dayOfWeekIso, [1, 3, 5], true)) {
            $weighIns[] = WeighIn::factory()->for($user)->create(['weight_kg' => round(90 - 0.05 * count($weighIns), 2), 'weighed_at' => $day->format('Y-m-d').' 07:00:00']);
        }
        $dates[] = $day->format('Y-m-d');
    }
    Step::query()->insert(array_map(fn (string $date) => ['id' => (string) Str::ulid(), 'user_id' => $user->id, 'date' => $date, 'count' => 8000, 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()], $dates));

    return [$user, $weighIns, array_reverse($dates)];
}

/**
 * Every page of the user's weigh_ins_show, newest first, following next_before.
 *
 * @return list<array<string, mixed>>
 */
function weighInPages(User $user): array
{
    $pages = [weighInCall($user, 'weigh_ins_show')->assertOk()->json('data')];
    while (($before = end($pages)['next_before']) !== null && count($pages) < 20) {
        $pages[] = weighInCall($user, 'weigh_ins_show', ['before' => $before])->assertOk()->json('data');
    }

    return $pages;
}

/** @param list<WeighIn> $weighIns */
function weighInTrendLabel(array $weighIns): string
{
    $points = array_map(fn (WeighIn $w) => new WeighInPoint((string) $w->id, $w->weighed_at->toDateTimeImmutable(), (float) $w->weight_kg), $weighIns);

    return BodyMeasures::weightLabel((new TrendWeight($points, new DateTimeZone('UTC')))->currentKg(), 'kg');
}

it('WT-004 WT-020 pages the weigh-ins, missed days and steps 90 days at a time, newest first, back to the first', function () {
    Carbon::setTestNow('2026-10-10 12:00:00');
    [$user, $weighIns, $stepDates] = weighInYear();

    $pages = weighInPages($user);

    expect(WeighInsPayload::PAGE_DAYS)->toBe(90)
        ->and(array_column($pages, 'next_before'))->toBe(['2026-07-13', '2026-04-14', '2026-01-14', '2025-10-16', null]);
    assertMatchesScreenFixture('weigh_ins_show', $pages[0]);
    // Each page is its window: the newest [2026-07-13, 2026-10-11), the next [2026-04-14, 2026-07-13).
    expect(collect($pages[0]['rows'])->min('weighed_at'))->toBeGreaterThanOrEqual('2026-07-13')
        ->and(collect($pages[0]['steps']['rows'])->min('date'))->toBe('2026-07-13')
        ->and(collect($pages[1]['rows'])->max('weighed_at'))->toBeLessThan('2026-07-13')
        ->and(collect($pages[1]['rows'])->min('weighed_at'))->toBeGreaterThanOrEqual('2026-04-14')
        ->and(collect($pages[1]['steps']['rows'])->max('date'))->toBe('2026-07-12')
        ->and(collect($pages[1]['steps']['rows'])->min('date'))->toBe('2026-04-14');

    // Every weigh-in and every missed day once, newest first, across the pages.
    $times = array_map(fn (WeighIn $w) => $w->weighed_at->toDateTimeImmutable(), $weighIns);
    $expected = array_map(fn (WeighIn $w) => [$w->weighed_at->format('Y-m-d H:i'), (string) $w->id], $weighIns);
    foreach (WeighInSchedule::missedDays([1, 3, 5, 6], $times, Carbon::now()->toDateTimeImmutable()) as $day) {
        $expected[] = [$day->format('Y-m-d').($day->format('N') === '6' ? ' 08:30' : ' 07:00'), 'missed-'.$day->format('Y-m-d')];
    }
    usort($expected, fn (array $a, array $b) => $b[0] <=> $a[0]);
    $ids = array_merge(...array_map(fn (array $page) => array_column($page['rows'], 'id'), $pages));
    $dates = array_merge(...array_map(fn (array $page) => array_column($page['steps']['rows'], 'date'), $pages));

    expect($ids)->toBe(array_column($expected, 1))
        ->and($ids)->toContain('missed-2025-10-11', 'missed-2026-10-03')
        ->and($dates)->toBe($stepDates);
});

it('WT-004 WT-020 puts each day on one page: a day starting the newest window on it, the day before on the next', function () {
    Carbon::setTestNow('2026-10-10 12:00:00');
    $gappy = weighInScenario();
    WeighIn::factory()->for($gappy)->create(['weighed_at' => '2026-06-01 07:00:00']);
    WeighIn::factory()->for($gappy)->create(['weighed_at' => '2026-10-09 07:00:00']);
    $edge = weighInScenario();
    $late = WeighIn::factory()->for($edge)->create(['weighed_at' => '2026-07-12 23:59:59']);
    $midnight = WeighIn::factory()->for($edge)->create(['weighed_at' => '2026-07-13 00:00:00']);
    Step::factory()->for($edge)->create(['date' => '2026-07-12', 'count' => 1200]);
    Step::factory()->for($edge)->create(['date' => '2026-07-13', 'count' => 1300]);

    [$newest, $older] = weighInPages($gappy);
    expect($newest['next_before'])->toBe('2026-07-13')
        ->and(array_column($newest['rows'], 'id'))->toContain('missed-2026-07-13')->not->toContain('missed-2026-07-11')
        ->and(array_column($older['rows'], 'id'))->toContain('missed-2026-07-11')->not->toContain('missed-2026-07-13');

    [$newest, $older] = weighInPages($edge);
    expect(array_column($newest['rows'], 'id'))->toContain($midnight->id)->not->toContain($late->id)
        ->and(array_column($newest['steps']['rows'], 'date'))->toBe(['2026-07-13'])
        ->and(array_column($older['rows'], 'id'))->toContain($late->id)->not->toContain($midnight->id)
        ->and(array_column($older['steps']['rows'], 'date'))->toBe(['2026-07-12']);
});

it('WT-010 WT-011 shows the trend of every weigh-in on every page, the weight Today shows, never a page\'s own', function () {
    Carbon::setTestNow('2026-10-10 12:00:00');
    [$user, $weighIns] = weighInYear();

    $pages = weighInPages($user);
    $all = weighInTrendLabel($weighIns);

    expect(array_unique(array_column(array_column($pages, 'trend'), 'value')))->toBe([$all])
        ->and($all)->toBe(BodyMeasures::weightLabel(TodayWeight::kg($user, $user->profile), 'kg'));
    // A trend over one page's weigh-ins differs. The newest page's has caught up to a tenth of a kilo after 90 days, so
    // the older pages are the ones a page-only trend gives away on.
    foreach (array_slice($pages, 1) as $page) {
        $own = array_values(array_filter($weighIns, fn (WeighIn $w) => in_array($w->id, array_column($page['rows'], 'id'), true)));
        expect(weighInTrendLabel($own))->not->toBe($all);
    }
});

it('WT-004 never shows another user\'s weigh-ins, steps or schedule on any page, nor pages on for them', function () {
    Carbon::setTestNow('2026-10-10 12:00:00');
    $other = User::factory()->create();
    WeighInScheduleEntry::factory()->for($other)->create(['weekdays' => [2, 4], 'time' => '06:00']);
    foreach (['2025-01-01', '2025-10-14', '2026-01-02', '2026-05-05', '2026-08-04', '2026-10-08'] as $date) {
        WeighIn::factory()->for($other)->create(['weighed_at' => "{$date} 06:00:00"]);
        Step::factory()->for($other)->create(['date' => $date, 'count' => 500]);
    }
    [$user, $weighIns, $stepDates] = weighInYear();

    $pages = weighInPages($user);

    $ids = array_merge(...array_map(fn (array $page) => array_column($page['rows'], 'id'), $pages));
    $steps = array_merge(...array_map(fn (array $page) => $page['steps']['rows'], $pages));
    expect(array_column($pages, 'next_before'))->toBe(['2026-07-13', '2026-04-14', '2026-01-14', '2025-10-16', null])
        ->and(array_values(array_filter($ids, fn (string $id) => ! str_starts_with($id, 'missed-'))))->toBe(array_reverse(array_map(fn (WeighIn $w) => $w->id, $weighIns)))
        ->and(array_filter($ids, fn (string $id) => str_starts_with($id, 'missed-') && Carbon::parse(substr($id, 7))->dayOfWeekIso !== 6))->toBe([])
        ->and(array_column($steps, 'date'))->toBe($stepDates)
        ->and(array_unique(array_column($steps, 'steps')))->toBe([8000])
        ->and(array_column($pages[0]['schedule']['entries'], 'days_label'))->toBe(['Mon, Wed, Fri', 'Sat']);
});

it('WT-004 reads the newest page for a null before, answers 422 for a malformed one, and an empty page before the first', function () {
    Carbon::setTestNow('2026-10-10 12:00:00');
    [$user] = weighInYear();
    $newest = weighInCall($user, 'weigh_ins_show')->assertOk()->json('data');

    weighInCall($user, 'weigh_ins_show', ['before' => 'not-a-date'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'before');
    expect(weighInCall($user, 'weigh_ins_show', ['before' => null])->assertOk()->json('data'))->toBe($newest);

    $oldest = weighInCall($user, 'weigh_ins_show', ['before' => '2025-07-01'])->assertOk()->json('data');
    expect($oldest['rows'])->toBe([])
        ->and($oldest['steps']['rows'])->toBe([])
        ->and($oldest['next_before'])->toBeNull()
        ->and($oldest['empty_message'])->toBeNull()
        ->and($oldest['trend'])->toBe($newest['trend'])
        ->and($oldest['schedule'])->toBe($newest['schedule'])
        ->and($oldest['steps']['default_date'])->toBe($newest['steps']['default_date']);
    foreach (weighInPages($user) as $page) {
        expect($page['empty_message'])->toBeNull();
    }
});

it('WT-004 reads a page in as many queries for a year of history as for three weigh-ins', function () {
    Carbon::setTestNow('2026-10-10 12:00:00');
    $few = weighInScenario();
    foreach (['2026-10-05', '2026-10-07', '2026-10-09'] as $date) {
        WeighIn::factory()->for($few)->create(['weighed_at' => "{$date} 07:00:00"]);
    }
    [$many] = weighInYear();

    $count = function (User $user): int {
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        weighInCall($user, 'weigh_ins_show')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    expect($count($many))->toBe($count($few));
    $newest = weighInCall($many, 'weigh_ins_show')->json('data');
    expect(collect($newest['rows'])->min('weighed_at'))->toBeGreaterThanOrEqual('2026-07-13')
        ->and(collect($newest['steps']['rows'])->min('date'))->toBeGreaterThanOrEqual('2026-07-13');
});
